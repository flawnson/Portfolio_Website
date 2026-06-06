 <?php
  echo function_exists('opcache_reset') && opcache_reset() ? 'opcache cleared' : 'no opcache / failed';