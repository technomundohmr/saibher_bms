<?php
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
$autoloader = require __DIR__ . '/autoload.php';
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);
$theme = \Drupal::service('theme.manager')->getActiveTheme()->getName();
$runtime = \Drupal::service('theme.registry')->getRuntime();
$ref = new \ReflectionObject($runtime);
foreach (['cache', 'cid'] as $p) { $prop = $ref->getProperty($p); $prop->setAccessible(TRUE); }
$backend = $ref->getProperty('cache')->getValue($runtime);
$cid = $ref->getProperty('cid')->getValue($runtime);
$backend->delete($cid);
\Drupal::service('cache.default')->delete('theme_registry:' . $theme);
\Drupal::service('cache.default')->delete('theme_registry:build:modules');
print "limpiado: " . $cid . " y theme_registry:" . $theme . "\n";
