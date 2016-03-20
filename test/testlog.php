<?php

$out = "";
for ($i = 0; $i <= 1500; $i++) {
    $out .= "$i." . PHP_EOL;
    if ($i % 100 == 0) {
        file_put_contents('/var/www/lv/log.txt', $out, FILE_APPEND);
        sleep(1);
        $out = "";
    }
}
