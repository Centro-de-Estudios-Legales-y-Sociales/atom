<div id="homepage-hero" class="row">

  <?php $cacheKey = 'homepage-nav-'.$sf_user->getCulture(); ?>
  <?php if (!cache($cacheKey)) { ?>
    <div class="span8" id="homepage-nav">
      <p><?php echo __('Browse by'); ?></p>
      <ul>
        <?php $icons = [
            'browseInformationObjects' => '/images/icons-large/icon-archival.png',
            'browseActors' => '/images/icons-large/icon-people.png',
            'browseRepositories' => '/images/icons-large/icon-institutions.png',
            'browseSubjects' => '/images/icons-large/icon-subjects.png',
            'browseFunctions' => '/images/icons-large/icon-functions.png',
            'browsePlaces' => '/images/icons-large/icon-places.png',
            'browseDigitalObjects' => '/images/icons-large/icon-media.png', ]; ?>
        <?php $browseMenu = QubitMenu::getById(QubitMenu::BROWSE_ID); ?>
        <?php if ($browseMenu->hasChildren()) { ?>
          <?php foreach ($browseMenu->getChildren() as $item) { ?>
            <li>
              <a href="<?php echo url_for($item->getPath(['getUrl' => true, 'resolveAlias' => true])); ?>">
                <?php if (isset($icons[$item->name])) { ?>
                  <?php echo image_tag($icons[$item->name], ['width' => 42, 'height' => 42, 'alt' => '']); ?>
                <?php } ?>
                <?php echo esc_specialchars($item->getLabel(['cultureFallback' => true])); ?>
              </a>
            </li>
          <?php } ?>
        <?php } ?>
      </ul>
    </div>
    <?php cache_save($cacheKey); ?>
  <?php } ?>

  <div class="span3" id="intro">
    <?php if ('fr' == $sf_user->getCulture()) { ?>
      <h2>
        <span class="title">Archivo CELS</span>
        Fondos y colecciones del Archivo CELS
      </h2>
      <p>En esta web ponemos a disposición del público las descripciones archivísticas de los fondos y colecciones que el Archivo del CELS reúne, organiza y conserva.<br />Desde hace cuarenta años nuestra misión es documentar, litigar, investigar y acompañar a las victimas de violaciones a los derechos humanos en nuestro país.
      </p>
    <?php } else { ?>
      <h2>
        <span class="title">Archivo Cels</span>
        st
      </h2>
      <p>Desde hace cuarenta años nuestra misión es documentar, litigar, investigar y acompañar a las victimas de violaciones a los derechos humanos en nuestro país.
      </p>
    <?php } ?>
  </div>

</div>

<div id="homepage" class="row">

  <div class="span4">
    <?php echo get_component('default', 'popular', ['limit' => 10, 'sf_cache_key' => $sf_user->getCulture()]); ?>
  </div>

  <div class="span8" id="virtual-exhibit">
    <a href="https://www.cels.org.ar/">
      <h3>
        <?php echo __('Virtual exhibits'); ?><br />
        <span class="title">CELS</span>
        <span class="small">Centro de Estudios Sociales y Legales</span>
      </h3>
      <div>&nbsp;</div>
    </a>
  </div>

</div>
