{
    "nodeName": "myserver1",
    "logStreams": {
        "web": {
            "logFiles": [
                ".../access.log",
                ".../error.log"
            ],
            "active": true
        },
        "php": {
            "logFiles": [
                ".../common_php.log"
            ],
            "active": true
        },
        "debug_log": {
            "logFiles": [
                "/var/www/lv/log.txt"
            ],
            "active": true
        },
        "testdb": {
            "mysql_host": "127.0.0.1",
            "mysql_port": "3306",
            "mysql_db": "xxx",
            "mysql_user": "user",
            "mysql_pass": "password",
            "active": true
        }
    }
}

