# Janokapapa\PHPTailLogs

Simple php logs tail in the browser. Inspired by log.io and based on https://code.google.com/p/php-tail/.

**Features:**
- handle any number of logs
- basic search functionality in lines
- filter log streams
- highlight errors/exceptions (configurable words)
- also bookmark errors/exceptions and jump to the lines on click

**Example apache config:**

    <VirtualHost *:80>
      DocumentRoot ".../phptaillog"
      ServerName phptaillog
      ErrorLog .../error.log
      CustomLog .../access.log combined
      SetEnv TAILLOG_CONFIGURATION example_config
      <Directory ".../phptaillog">
        allow from all
        Options None
        Require all granted
      </Directory>
    </VirtualHost>

**Add to hosts file:**

your.server.ip phptaillog

Then create a /config/xyz.json config file based on example_config.json

Navigate for the address: http://phptaillog/

Enjoy logs.
