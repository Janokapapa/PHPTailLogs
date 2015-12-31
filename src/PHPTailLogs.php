<?php

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
        //load config from file
        $this->setConfig((array) json_decode(file_get_contents('config/' . $config_name . '.json')));
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
     * Load state of current position in log files
     */
    protected function loadState()
    {
        $this->state = array();
        if ($state_from_file = file_get_contents('var/state.json')) {
            $this->state = (array) json_decode($state_from_file);
        }
    }

    /**
     * Save state of current position in log files
     */
    protected function saveState()
    {
        file_put_contents('var/state.json', json_encode($this->state));
    }

    /**
     * This function is in charge of retrieving the latest lines from the log file
     * @return Returns the JSON representation of the nodes-streams-colors-lines array
     */
    public function getNewLines()
    {
        $this->loadState();

        $data = array();

        $i = 0;
        foreach ($this->getConfig()['logStreams'] as $stream_name => $v) {
            foreach ($v as $e) {
                $new_lines = $this->getNewLinesFromFile($e);
                if (is_array($new_lines)) {
                    $tagged_lines = array();
                    foreach ($new_lines as $line) {
                        $tagged_line = array();
                        $tagged_line['nodeName'] = $this->getConfig()['nodeName'];
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

        return json_encode($data);
    }

    /**
     * Get last fetched size of a file
     * @param string $file
     * @return int
     */
    protected function getLastFetchedSize($file)
    {
        if ($this->state[$file]) {
            return $this->state[$file];
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
        $this->state[$file] = $file_size;
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
        $fsize = filesize($file);
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
        $data = array();
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
                <meta http-equiv="content-type" content="text/html;charset=utf-8" />

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

                    var resultLines = new Array();
                    var searchLines = new Array();

                    var streamFilterColor = new Object();
                    var streamFilterActive = new Object();

                    $(document).ready(function () {
                        //Focus on the textarea
                        $("#grep").focus();
                        //Set up an interval for updating the log. Change updateTime in the PHPTailLogs contstructor to change this
                        setInterval("updateLog()", <?php echo $this->updateTime; ?>);
                        //Some window scroll event to keep the menu at the top
                        $(window).scroll(function (e) {
                            if ($(window).scrollTop() > 0) {
                                $('.float').css({
                                    position: 'fixed',
                                    top: '0',
                                    left: 'auto'
                                });
                            } else {
                                $('.float').css({
                                    position: 'static'
                                });
                            }
                        });

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

                    });

                    //This function scrolls to the bottom
                    function scrollToBottom() {
                        $("html, body").scrollTop($(document).height());
                    }

                    //This function queries the server for updates.
                    function updateLog() {
                        $.getJSON('/?ajax=1', function (data) {
                            if (data && data.length > 0) {
                                resultLines = resultLines.concat(data);
                                doSearchLines();
                            }
                        });
                    }

                    function isEmpty(str) {
                        return (!str || 0 === str.length);
                    }

                    function doSearchLines() {
                        var word = $('#grep').val();
                        if (isEmpty(word)) {
                            appendLines(resultLines);
                            searchLines = '';
                            if (scroll) {
                                scrollToBottom();
                            }
                            return;
                        }
                        searchLines = $.grep(resultLines, function (e, index) {
                            return e.line.toLowerCase().indexOf(word.toLowerCase()) >= 0;
                        });

                        appendLines(searchLines);
                        $("#results").highlight(word);
                    }

                    function appendLines(lines) {
                        $.each(lines, function (key, node) {
                            if (node.line) {
                                streamFilterColor[node.streamName] = node.color;
                                if (streamFilterActive[node.streamName] === undefined || streamFilterActive[node.streamName] === null) {
                                    streamFilterActive[node.streamName] = true;
                                }
                                if (streamFilterActive[node.streamName]) {//only active streams
                                    $("#results").append('<span style="color: #eeeeee;">' + node.nodeName + '</span> <span style="color: ' + node.color + '"> ' + node.streamName + '</span> ' + node.line + '<br/>');
                                }
                            }
                        });
                        showFilters();
                    }

                    function applyFilter() {
                        $("#results").html('');
                        doSearchLines();
                    }

                    function clearGrep() {
                        $('#grep').val('');
                        doSearchLines();
                    }

                    function clearLines() {
                        resultLines = new Array();
                        $("#results").html('');
                        streamFilterColor = new Object();
                        streamFilterActive = new Object();
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
                            });
                        });
                    }
                    /* ]]> */
                </script>
            </head>
            <body>
                <div class="float">
                    <input id="grep" type="text" value="" />
                    <button onclick="clearGrep();">Empty</button>
                    <button onclick="clearLines();">Clear</button>
                    <!--<button onclick="updateLog();">Update</button>-->
                    <div id="streamFilters"></div>
                </div>
                <div id="results">
                </div>
            </body>
        </html>
        <?php
    }
}
