<?php
require_once 'db.php';

class PHPTailLogs
{

    /**
     * Colors for different streams
     * @var array
     */
    private $colors = ['#ff0000', '#8888ff', '#aaaaaa', '#ff00ea', '#ffff00', '#00e4ff', '#96b6ff', '#ffae00', '#00ff42', '#008f25'];

    /**
     * Configuration
     * @var array
     */
    private $config;

    /**
     * The time between AJAX requests to the server.
     *
     * Setting this value too high with an extremly fast-filling log will cause your PHP application to hang.
     * @var integer
     */
    private $updateTime;

    /**
     * This variable holds the maximum amount of bytes this application can load into memory (in bytes).
     * @var string
     */
    private $maxSizeToLoad;

    /**
     * To store the current state
     * @var array
     */
    private $state;

    /**
     *
     * PHPTailLogs constructor
     * @param integer $defaultUpdateTime The time between AJAX requests to the server.
     * @param integer $maxSizeToLoad This variable holds the maximum amount of bytes this application can load into memory (in bytes). Default is 20 Megabyte = 20971520 byte
     */
    public function __construct($defaultUpdateTime = 2000, $maxSizeToLoad = 20971520)
    {
        $config_name = getenv('TAILLOG_CONFIGURATION');

        if (empty($config_name)) {
            error_log('taillog error: no config name');
        }

        //load config from file
        $config_file = 'config/' . $config_name . '.json';

        if (!file_exists($config_file)) {
            error_log('taillog error: file doesnt exists: ' . $config_file);
        }

        $raw_config = file_get_contents($config_file);
        $config = json_decode($raw_config);

        if (empty($config)) {
            error_log('taillog error: no valid config in file: ' . $raw_config);
        }

        $this->setConfig($config);
        $this->updateTime = $defaultUpdateTime;
        $this->maxSizeToLoad = $maxSizeToLoad;
    }

    /**
     * Get current configuration
     * @return array
     */
    protected function getConfig()
    {
        return $this->config;
    }

    /**
     * Set current configuration
     * @param type $config
     */
    protected function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * Save current configuration to current config file
     */
    protected function saveConfig()
    {
        $config_name = getenv('TAILLOG_CONFIGURATION');

        file_put_contents('config/' . $config_name . '.json', json_encode($this->getConfig(), JSON_PRETTY_PRINT));
    }

    /**
     * Load state of current position in log files
     */
    protected function loadState()
    {
        $this->state = [];
        if ($state_from_file = file_get_contents('var/state.json')) {
            $this->state = (array)json_decode($state_from_file);
        }
    }

    /**
     * Save state of current position in log files
     */
    protected function saveState()
    {
        file_put_contents('var/state.json', json_encode($this->state, JSON_PRETTY_PRINT));
    }

    /**
     * This function is in charge of retrieving the latest lines from the log file
     * @return Returns the JSON representation of the nodes-streams-colors-lines array
     */
    public function getNewLines()
    {
        $this->loadState();

        $data = [];

        $i = 0;
        foreach ($this->getConfig()->logStreams as $stream_name => $o) {
            if (property_exists($o, 'logFiles') && $o->logFiles && property_exists($o, 'active') && $o->active) {
                foreach ($o->logFiles as $e) {
                    $new_lines = $this->getNewLinesFromFile($e);
                    if (is_array($new_lines)) {
                        $tagged_lines = [];
                        foreach ($new_lines as $line) {
                            $tagged_line = [];
                            $tagged_line['nodeName'] = $this->getConfig()->nodeName;
                            $tagged_line['streamName'] = $stream_name;
                            $tagged_line['color'] = $this->colors[$i % 10];
                            $tagged_line['line'] = $line;
                            $tagged_lines[] = $tagged_line;
                        }
                        $data = array_merge($data, $tagged_lines);
                        $i++;
                    }
                }
            }

            if (property_exists($o, 'mysql_host') && $o->mysql_host && $o->active) {
                $db = new DB($o->mysql_host, $o->mysql_port, $o->mysql_db, $o->mysql_user, $o->mysql_pass, 'UTF8', false);

                $new_lines = $this->getNewLinesFromDB($db, $o->mysql_host . ':' . $o->mysql_db);
                if (is_array($new_lines)) {
                    $tagged_lines = [];
                    foreach ($new_lines as $line) {
                        $tagged_line = [];
                        $tagged_line['nodeName'] = $this->getConfig()->nodeName;
                        $tagged_line['streamName'] = $stream_name;
                        $tagged_line['color'] = $this->colors[$i % 10];
                        $tagged_line['line'] = $line;
                        $tagged_lines[] = $tagged_line;
                    }
                    $data = array_merge($data, $tagged_lines);
                    $i++;
                }
            }
        }

        $this->saveState();

        return $data;
    }

    public function getFilters()
    {
        $filters = new stdClass();
        foreach ($this->getConfig()->logStreams as $stream_name => $o) {
            $filters->$stream_name = $o->active;
        }
        return $filters;
    }

    public function storeFilters($filters)
    {
        foreach ($this->getConfig()->logStreams as $stream_name => & $o) {
            if (array_key_exists($stream_name, $filters)) {
                $o->active = false;
                if ($filters[$stream_name] === 'true') {
                    $o->active = true;
                }
            }
        }

        $this->saveConfig();
    }

    /**
     * Set the file pointers to the end of the log files
     */
    public function initLinesState()
    {
        foreach ($this->getConfig()->logStreams as $stream_name => $o) {
            if ($o->logFiles) {
                foreach ($o->logFiles as $e) {
                    $this->initLineState($e);
                }
            }
        }

        $this->saveState();
    }

    /**
     * Set the file pointer to the end of the log file
     */
    protected function initLineState($file)
    {
        clearstatcache();
        $fsize = 0;
        if (file_exists($file)) {
            $fsize = filesize($file);
        }
        $this->setlastFetchedSize($file, $fsize);
    }

    /**
     * Get last fetched size of a file
     * @param string $file
     * @return int
     */
    protected function getLastFetchedSize($file)
    {
        if ($this->state[$file]) {
            return (int)$this->state[$file];
        }
        return 0;
    }

    /**
     * Set in the state of last fetched size of the file
     * @param string $file
     * @param int $file_size
     */
    protected function setLastFetchedSize($file, $file_size)
    {
        $this->state[$file] = (int)$file_size;
    }

    /**
     * Get new lines for the given file
     * @param string $file
     * @return array New lines from file
     */
    protected function getNewLinesFromFile($file)
    {

        /**
         * Clear the stat cache to get the latest results
         */
        clearstatcache();
        /**
         * Define how much we should load from the log file
         * @var
         */
        $fsize = 0;
        if (file_exists($file)) {
            $fsize = filesize($file);
        } else {
            return array('Error: Config input file doesnt exists: ' . $file);
        }
        $maxLength = ($fsize - $this->getlastFetchedSize($file));
        $this->setlastFetchedSize($file, $fsize);
        /**
         * Verify that we don't load more data then allowed.
         */
        if ($maxLength > $this->maxSizeToLoad) {
            return array("ERROR: PHPTailLogs attempted to load more (" . round(($maxLength / 1048576), 2) . "MB) then the maximum size (" . round(($this->maxSizeToLoad / 1048576), 2) . "MB) of bytes into memory. You should lower the defaultUpdateTime to prevent this from happening. ");
        }
        /**
         * Actually load the data
         */
        $data = [];
        if ($maxLength > 0) {
            $fp = fopen($file, 'r');
            fseek($fp, -$maxLength, SEEK_END);
            $data = explode("\n", fread($fp, $maxLength));
        }

        /**
         * If the last entry in the array is an empty string lets remove it.
         */
        if (end($data) == "") {
            array_pop($data);
        }

        return $data;
    }

    /**
     * Get new lines for the given DB
     * @param DB $db
     * @return array New lines from DB
     */
    protected function getNewLinesFromDB($db, $db_name)
    {
        $last_id = $this->getlastFetchedSize($db_name);

        try {
            $last_row_id = $db->run("SELECT id FROM log WHERE id = :last_id ORDER BY id", array('last_id' => $last_id))->fetchColumn();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return;
        }

        if (!$last_row_id) {//table emptied
            $last_id = 0;
        }

        $rows = $db->run("SELECT * FROM log WHERE id > :last_id ORDER BY id LIMIT 1000", array('last_id' => $last_id))->fetchAll();

        if (count($rows) === 0) {
            return;
        }

        $data = array();
        foreach ($rows as $r) {
            $data[] = $r['log_time'] . '|' . $r['session_id'] . '|' . $r['source'] . '|' . $r['message'] . " data=" . $r['raw'];
            $id = $r['id'];
        }

        $this->setlastFetchedSize($db_name, $id);

        return $data;
    }

    /**
     * This function will print out the required HTML/CSS/JS
     */
    public function generateGUI()
    {
        ?>
        <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
                "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
        <html xmlns="http://www.w3.org/1999/xhtml">
        <head>
            <title>TailLogs</title>
            <meta http-equiv="content-type" content="text/html;charset=utf-8"/>

            <script type="text/javascript" src="/js/jquery-1.11.3.min.js"></script>
            <script type="text/javascript" src="/js/jquery.highlight-5.js"></script>

            <link rel="stylesheet" type="text/css" href="/css/style.css"></link>

            <script type="text/javascript">
                /* <![CDATA[ */
                //Grep keyword
                var grep = "";
                //Should the Grep be inverted?
                var invert = 0;
                //Last known document height
                var documentHeight = 0;
                //Last known scroll position
                var scrollPosition = 0;
                //Should we scroll to the bottom?
                var scroll = true;
                //how many lines to keep in browser
                var maxNumLines = 10000;

                var resultLines = new Array();
                var searchLines = new Array();
                var errorLines = new Array();

                var streamFilterColor = new Object();
                var streamFilterActive = new Object();

                var config;

                //words activate red line error mark
                var error_strings = new Array('exception', 'FATAL', 'ERROR', 'Fatal error');
                var error_strings_not_including = new Array('ERROR CODE');

                $(document).ready(function () {
                    //Focus on the textarea
                    $("#grep").focus();

                    getFilterActiveFromServer();

                    //Set up an interval for updating the log. Change updateTime in the PHPTailLogs contstructor to change this
                    setInterval("updateLog()", <?php echo $this->updateTime; ?>);

                    //If window is resized should we scroll to the bottom?
                    $(window).resize(function () {
                        if (scroll) {
                            scrollToBottom();
                        }
                    });

                    //Handle if the window should be scrolled down or not
                    $(window).scroll(function () {
                        documentHeight = $(document).height();
                        scrollPosition = $(window).height() + $(window).scrollTop();
                        if (documentHeight <= scrollPosition) {
                            scroll = true;
                        } else {
                            scroll = false;
                        }
                    });
                    scrollToBottom();

                    $('#grep').keypress(function (e) {
                        var key = e.which;
                        if (key === 13) {
                            doGrep();
                            return false;
                        }
                    })
                });

                //This function scrolls to the bottom
                function scrollToBottom() {
                    $("html, body").scrollTop($(document).height());
                }

                //This function queries the server for updates.
                function updateLog() {
                    $.getJSON('/?ajax=1', function (data) {
                        if (data && data.length > 0) {
                            var resultLinesNum = resultLines.length;
                            var newLinesNum = data.length;
                            var linesToDelete = (resultLinesNum + newLinesNum) - maxNumLines;

                            if (linesToDelete < 0) {
                                linesToDelete = 0;
                            }

                            if (linesToDelete > 0) {
                                if (linesToDelete > resultLinesNum) {
                                    resultLines = new Array(); //delete all
                                    linesToDelete = linesToDelete - resultLinesNum;
                                    data.splice(0, linesToDelete); //delete remains from new lines from server
                                    $("#results").html('');
                                } else {
                                    $("#results").find('.result_line:lt(' + linesToDelete + ')').remove();
                                    resultLines.splice(0, linesToDelete);
                                }
                            }

                            resultLines = resultLines.concat(data);
                            doSearchLines(newLinesNum);
                        }
                    });
                }

                function getFilterActiveFromServer() {
                    $.getJSON('/?get_filters=1', function (data) {
                        if (data) {
                            streamFilterActive = data;
                        }
                    });
                }

                function putFilterActiveToServer() {
                    $.post("/?put_filters=1", streamFilterActive);
                }

                function getFilterActive(node_name) {
                    console.log(config.node_name);
                }

                function isEmpty(str) {
                    return (!str || 0 === str.length);
                }

                function doSearchLines(newLinesNum) {
                    var word = $('#grep').val();
                    if (isEmpty(word)) {
                        appendLines(resultLines, newLinesNum);
                        searchLines = '';
                        if (scroll) {
                            scrollToBottom();
                        }
                        return;
                    }

                    doGrep();
                }

                function doGrep() {
                    $("#results").html('');
                    var word = $('#grep').val();
                    searchLines = $.grep(resultLines, function (e, index) {
                        return e.line.toLowerCase().indexOf(word.toLowerCase()) >= 0;
                    });
                    appendLines(searchLines, 0); //append all lines
                    $("#results").highlight(word);
                    if (scroll) {
                        scrollToBottom();
                    }
                }

                function appendLines(lines, newLinesNum) {
                    if (newLinesNum > 0) {
                        linesNum = lines.length;
                        newLines = lines.slice(linesNum - newLinesNum, linesNum);
                    } else {
                        newLines = lines;
                    }

                    var lineCounter = linesNum;
                    var errorBefore = false;

                    $.each(newLines, function (key, node) {
                        if (node.line) {
                            streamFilterColor[node.streamName] = node.color;
                            if (streamFilterActive[node.streamName] === undefined || streamFilterActive[node.streamName] === null) {
                                streamFilterActive[node.streamName] = true;
                            }
                            if (streamFilterActive[node.streamName]) {//only active streams
                                //check for lines with errors
                                lineClass = 'result_line';
                                bookMark = lineCounter;

                                has_error = new RegExp(error_strings.join("|")).test(node.line);
                                has_error_not_including = new RegExp(error_strings_not_including.join("|")).test(node.line);

                                if ((has_error && !has_error_not_including) || errorBefore) {
                                    lineClass = 'result_line_error';
                                }

                                if (has_error && !has_error_not_including) {
                                    bookMark = '<span id="' + lineCounter + '">' + lineCounter + '</span>';
                                    if (errorLines[errorLines.length - 1] + 1 < lineCounter || errorLines.length === 0) {
                                        errorLines.push(lineCounter);
                                    }
                                    errorBefore = true; //keep red error lines until it disengaged
                                } else if (errorBefore && !(node.line.indexOf('#') > -1 || node.line.indexOf('Stack trace') > -1)) {//disengage error lines
                                    errorBefore = false;
                                    lineClass = 'result_line';
                                }

                                //append lines
                                $("#results").append('<div class="' + lineClass + '"><span style="color: #eeeeee;">' + bookMark + ' ' + node.nodeName + '</span> <span style="color: ' + node.color + '"> ' + node.streamName + '</span> ' + node.line + '</div>');
                            }

                            lineCounter++;
                        }
                    });

                    showFilters();
                    showExceptionResults();
                }

                function applyFilter() {
                    $("#results").html('');
                    doSearchLines();
                }

                function clearGrep() {
                    $('#grep').val('');
                    doGrep();
                }

                function clearLines() {
                    resultLines = new Array();
                    $("#results").html('');
                    errorLines = new Array();
                    $("#errorBookMarks").html('');
                }

                function showFilters() {
                    $("#streamFilters").html('');
                    $.each(streamFilterColor, function (streamName, color) {
                        var filterId = "filter_" + streamName;
                        $("#streamFilters").append(' <label style="color: ' + color + '"><input type="checkbox" id="' + filterId + '" value="xxx"> ' + streamName + '</label> ');

                        $('#' + filterId).prop('checked', false);
                        if (streamFilterActive[streamName]) {
                            $('#' + filterId).prop('checked', true);
                        }

                        $('#' + filterId).change(function () {
                            streamFilterActive[streamName] = $('#' + filterId).prop('checked');
                            applyFilter();
                            putFilterActiveToServer();
                        });
                    });
                }

                function showExceptionResults() {
                    var errorBookMarks = 'Errors in lines: ';
                    $.each(errorLines, function (key, node) {
                        errorBookMarks += ' <a href="#' + node + '">' + node + '<a>';
                    });
                    $('#errorBookMarks').html(errorBookMarks);
                }

                /* ]]> */
            </script>
        </head>
        <body>
        <div class="float">
            <input id="grep" type="text" value=""/>
            <button onclick="doGrep();">Search</button>
            <button onclick="clearGrep();">Empty</button>
            <button onclick="clearLines();">Clear</button>
            <!--<button onclick="updateLog();">Update</button>-->
            <div id="streamFilters"></div>
            <div class="cb"></div>
            <div id="errorBookMarks"></div>
        </div>
        <div class="cb"></div>
        <div id="results">
        </div>
        </body>
        </html>
        <?php
    }

}
