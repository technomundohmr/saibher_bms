<?php
try {
  new \Drupal\saibher_bms_core\Service\DocumentDraft();
  echo "ok\n";
} catch (\Throwable $e) {
  echo $e->getMessage() . "\n";
}
