<?php

class arCelsPluginConfiguration extends sfPluginConfiguration
{
    public static $summary = 'Theme plugin, extension of arDominionPlugin.';
    public static $version = '0.0.1';

    public function contextLoadFactories(sfEvent $event)
    {
        $context = $event->getSubject();

        // Runtime less interpreter will be loaded if debug mode is enabled
        // Remember to avoid localStorage caching when dev machine is not localhost
        if ($context->getConfiguration()->isDebug()) {
            $context->response->addJavaScript('/vendor/less.js', 'last');
            $context->response->addStylesheet('/plugins/arCelsPlugin/css/main.less', 'last', ['rel' => 'stylesheet/less', 'type' => 'text/css', 'media' => 'all']);
        } else {
            $context->response->addStylesheet('/plugins/arCelsPlugin/css/min.css', 'last', ['media' => 'all']);
        }
    }

    public function initialize()
    {
        $this->dispatcher->connect('context.load_factories', [$this, 'contextLoadFactories']);

        $decoratorDirs = sfConfig::get('sf_decorator_dirs');
        $decoratorDirs[] = $this->rootDir.'/templates';
        sfConfig::set('sf_decorator_dirs', $decoratorDirs);

        $moduleDirs = sfConfig::get('sf_module_dirs');
        $moduleDirs[$this->rootDir.'/modules'] = false;
        sfConfig::set('sf_module_dirs', $moduleDirs);

        // Move this plugin to the top to allow overwriting
        // controllers and views from other plugin modules.
        $plugins = $this->configuration->getPlugins();
        if (false !== $key = array_search($this->name, $plugins)) {
            unset($plugins[$key]);
        }
        $this->configuration->setPlugins(array_merge([$this->name], $plugins));
    }
}
