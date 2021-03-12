<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

class arElasticSearchInformationObject extends arElasticSearchModelBase
{
  protected static
    $conn,
    $statement,
    $counter = 0;

  protected
    $errors = array();

  public function load()
  {
    // Get count of all information objects
    $sql  = 'SELECT COUNT(*)';
    $sql .= ' FROM '.QubitInformationObject::TABLE_NAME;
    $sql .= ' WHERE id > ?';

    $this->count = QubitPdo::fetchColumn($sql, array(QubitInformationObject::ROOT_ID));
  }

  public function populate()
  {
    $this->load();

    $this->addInformationObjects();

    return $this->errors;
  }

  public function addInformationObjects($options = array())
  {
    $skip = isset($options['skip']) ? $options['skip'] : 0;
    $limit = isset($options['limit']) ? $options['limit'] : $this->count;

    // Loop through hierarchy and add to search index
    $sql = "WITH RECURSIVE cte (id, parent_id) AS (
              SELECT id, parent_id
              FROM information_object
              WHERE parent_id = ?
              UNION ALL
              SELECT i.id, i.parent_id
              FROM information_object i
              INNER JOIN cte
              ON i.parent_id = cte.id
            )
            SELECT * FROM cte";

    $sql .= sprintf(" LIMIT %d, %d", $skip, $limit);

    $rows = QubitPdo::fetchAll(
      $sql,
      [QubitInformationObject::ROOT_ID],
      ['fetchMode' => PDO::FETCH_ASSOC]
    );

    $lastParentId = null;

    foreach ($rows as $row)
    {
      self::$counter++;

print 'ID:'. $row['id'] ."\n";
      try
      {
        if ($lastParentId != $row['parent_id'])
        {
          unset($options['ancestors']);
          unset($options['repository']);
          unset($option['inheritedCreators']);
        }

        $node = new arElasticSearchInformationObjectPdo($row['id'], $options);
        $data = $node->serialize();

        QubitSearch::getInstance()->addDocument($data, 'QubitInformationObject');

        $this->logEntry($data['i18n'][$data['sourceCulture']]['title'], self::$counter);

        // If under a different parent then cache related data
        if ($lastParentId != $row['parent_id'])
        {
          $options['ancestors'] = $node->getAncestors();
          $options['repository'] = $node->getRepository();
          $option['inheritedCreators'] = $node->inheritedCreators;          
        }

        $lastParentId = $row['parent_id'];
      }
      catch (sfException $e)
      {
        $this->errors[] = $e->getMessage();
      }
    }
  }

  public static function update($object, $options = array())
  {
    // Update description
    $node = new arElasticSearchInformationObjectPdo($object->id);
    QubitSearch::getInstance()->addDocument($node->serialize(), 'QubitInformationObject');

    // Update descendants if requested and they exists
    if ($options['updateDescendants'] && $object->rgt - $object->lft > 1)
    {
      self::updateDescendants($object);
    }
  }

  public static function updateDescendants($object)
  {
    // Update synchronously in CLI tasks and jobs
    $context = sfContext::getInstance();
    $env = $context->getConfiguration()->getEnvironment();
    if (in_array($env, array('cli', 'worker')))
    {
      foreach (self::getChildren($object->id) as $child)
      {
        // TODO: Use partial updates to only get and add
        // the fields that are inherited from the ancestors.
        // Be aware that transient descendants are entirely
        // added the first time to the search index in here
        // and they will require a complete update.
        self::update($child, array('updateDescendants' => true));
      }

      return;
    }

    // Update asynchronously in other environments
    $jobOptions = array(
      'ioIds' => array($object->id),
      'updateIos' => false,
      'updateDescendants' => true
    );
    QubitJob::runJob('arUpdateEsIoDocumentsJob', $jobOptions);

    // Let user know descendants update has started
    $jobsUrl = $context->routing->generate(null, array('module' => 'jobs', 'action' => 'browse'));
    $message = $context->i18n->__('Your description has been updated. Its descendants are being updated asynchronously – check the <a href="%1">job scheduler page</a> for status and details.', array('%1' => $jobsUrl));
    $context->user->setFlash('notice', $message);
  }

  public static function getChildren($parentId)
  {
    if (!isset(self::$conn))
    {
      self::$conn = Propel::getConnection();
    }

    if (!isset(self::$statement))
    {
      $sql  = 'SELECT
                  io.id,
                  io.lft,
                  io.rgt';
      $sql .= ' FROM '.QubitInformationObject::TABLE_NAME.' io';
      $sql .= ' WHERE io.parent_id = ?';
      $sql .= ' ORDER BY io.lft';

      self::$statement = self::$conn->prepare($sql);
    }

    self::$statement->execute(array($parentId));
    $children = self::$statement->fetchAll(PDO::FETCH_OBJ);

    return $children;
  }

  /**
   * Set boost values for various information object fields.
   *
   * @param array &$fields  A reference to our array of fields we're adding boost values to.
   * @param array $cultures  An array specifying which cultures the i18n fields cover.
   */
  public static function setBoostValues(&$fields, $cultures)
  {
    $i18nBoostFields = array(
      'i18n.%s.title' => 10,
      'subjects.i18n.%s.name' => 5,
      'creators.i18n.%s.authorizedFormOfName' => 6,
      'names.i18n.%s.authorizedFormOfName' => 3,
      'places.i18n.%s.name' => 3,
      'i18n.%s.scopeAndContent' => 5,
    );

    $nonI18nBoostFields = array(
      'identifier' => 5,
    );

    self::addBoostValuesToFields($fields, $i18nBoostFields, $nonI18nBoostFields);
  }

  public function getErrors()
  {
    return $this->errors;
  }
}
