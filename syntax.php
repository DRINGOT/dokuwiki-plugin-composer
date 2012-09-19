<?php

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/search.php');

/*
* {{compose> namespace#depth | print numbers how_deep absolute }}
*
* print:
* 		print optimization
* numbers + how_deep:
* 		numbering for headers(1.1.3 hallo...)
* absolute:
* 		absolute indentation of headers, corresponding to file-depth in namespace
* 		or (default) relative indentation
*/
class syntax_plugin_composer extends DokuWiki_Syntax_Plugin {

    /**
     * how to handle <p>
     */
    function getPType(){
        return 'block';
    }

    /**
     * what other types are allowed iside
     */
    function getAllowedTypes() {
        return array('substition');
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 311;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern("{{compose>.*?}}",$mode,'plugin_composer');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){

        global $conf;

        $opt = array(
            "level" => 0,
            "nons" => false,
            "relative" => false,
            "print" => false,
            "numbers" => false,
            "numbers_depth" => 2,
            "resort" => false
        );

        $givenOptString = "";
        $givenOpt = array();
        $content = "";

        $match = preg_replace("/\{\{compose\>(.*)\}\}/", "$1", $match);

        $nsOpt = preg_split("/\|/", $match);

        if (count($nsOpt) == 1) {

            $ns = $nsOpt[0];

        } else {

            $ns = $nsOpt[0];
            $givenOptString = $nsOpt[1];
            $givenOpt = preg_split("/ /", $givenOptString);

        }

        $nsLevel = preg_split("/#/", $ns);

        if (count($nsLevel) > 1) {

            $ns = $nsLevel[0];
            $opt["level"] = $nsLevel[1];

        }

        if (in_array("absolute", $givenOpt)) {

            if ($conf["useslashes"]) {

                $opt["relative"] = substr_count($ns, "/") + 1;

            } else {

                $opt["relative"] = substr_count($ns, ":") + 1;

            }

        }

        if (in_array("nons", $givenOpt)) {

            $opt["nons"] = true;

        }

        if (in_array("print", $givenOpt)) {

            $opt["split_too_long"] = true;

        }

        // Do we have the numbers option without a level specification?

        if (in_array("numbers", $givenOpt)) {

            $opt["numbers"] = true;

        }

        // Do we have the numbers option with the level specification?

        if (preg_match("/numbers:(\d)*/", $givenOptString, $matches)) {

            $opt["numbers"] = true;
            $opt["numbers_depth"] = $matches[1];

        }

        if (in_array("resort", $givenOpt)) {

            $opt["resort"] = true;

        }

        return array(
            'namespace'=> $ns,
            'options' => $opt
        );
    }

    /**
     * Create output
     */
    function render($mode, &$renderer, $data) {
        if($mode == 'xhtml'){
            //for header numbering
            $header_count = array(	1=>0,
                2=>0,
                3=>0,
                4=>0,
                5=>0,
            );

            $data['header_count'] = &$header_count;

            // get all files in the supplied namespace, with their level
            $file_data = $this->_get_file_data($data);

            /*
             * Resort results to optimize output
             * Sort by levels, then first sort the directories,
             * than the start page and then the files
             */

            if ($data["options"]["resort"]) {

                usort($file_data, "composer_sort_filedata");

            }

            foreach($file_data as $file) {
                // build the output
                $this->_render_output($renderer, $file, $data);
            }

            return true;
        }

        return false;
    }

    /*
     * Get all files within the namespace
     */
    function _get_file_data($data) {
        global $conf;

        $options = $data['options'];
        $ns = $data['namespace'];

        $resolvedPage = "";
        $pageExists = false;

        // Reformat namespace to filename

        // namespace options . or ..

        $pageFile = wikiFN($ns);

        if (!file_exists($pageFile)) {

            // Try adding the start page

            if ($conf["useslash"]) {

                $ns .= "/";

            } else {

                $ns .= ":";

            }

            $ns .= $conf['start'];

            $pageFile = wikiFN($ns);

            if (!file_exists($pageFile)) {

                return false;

            }

        }

        $dirname = dirname($pageFile);
        $dirname = str_replace($conf['datadir'], "", $dirname);

        $file_data = array(
            'start_lvl' => substr_count($dirname, DIRECTORY_SEPARATOR),
            'blocked' => array(),					    //blocked files
        );

        // find all matching files

        search(
            $file_data,
            $conf['datadir'],
            'composer_search_index',
            $options,
            $dirname
        );

        // clean the file_data from non-files (for safety)
        unset($file_data['start_lvl']);
        unset($file_data['blocked']);

        return $file_data;
    }


    /*
     * Render the output
     */

    function _render_output(&$renderer, $file_data, $o){

        // file attributes
        $id = $file_data['id'];
        $open =	$file_data['open'];
        $content = "";

        // build with relative indentation

        $clevel = $file_data['level'];

        if($o['options']['relative']){

            // Relative identation

            $clevel -= $o['options']['relative'];

        }

        // Don't open the file (in case of directories and nons on)

        if(!$open) {

            return false;

        }

        // Convert a:b -> home/data/pages/a/b

        $file = wikiFN($id);

        // Get data(in instructions format) from $file (dont use cache: false)

        $instr = p_cached_instructions($file, false);

        // Page was not empty

        if (!empty($instr)) {

            // Fix relative links and lower headers of included pages

            //we dont need this option for conversion

            unset($o['options']['relative']);

            $instr   = $this->_convertInstructions(
                $instr,
                $id,
                $renderer,
                $clevel,
                $o
            );

            $info = array();

            // Render page

            $content = p_render('xhtml', $instr, $info);

            // Remove TOC`s, section edit buttons and tags

            $content = $this->_cleanXHTML($content);
        }

        // Embed the included page

        $renderer->doc .= '<div class="include">';

        // Add an anchor to find start of a inserted page

        $id = str_replace(":", "_", $file_data['id']);
        $renderer->doc .= "<a name='$id' id='$id'>";
        $renderer->doc .= $content;
        $renderer->doc .= '</div>';

        return true;

    }

    /*
     * Corrects relative internal links and media and
     * converts headers of included pages to subheaders of the current page
     */
    function _convertInstructions($instr, $incl, &$renderer, $clevel, $o){

        global $ID;

        // check if included page is already in output namespace
        $iNS =	getNS($incl);
        $iID =	getNS($ID);

        //the content belongs to the original page
        if ($iID == $iNS) {
            $convert = false;	//just leave as it is
        } else {
            // The content was newly included, and is not original page content
            $convert = true;	//convert content
        }

        for ($i = 0; $i < count($instr); $i++){

            if ($convert) {

                if((substr($instr[$i][0], 0, 8) == 'internal')){

                    // Internal links(links inside this wiki) an relative links

                    $this->_convert_link($renderer,$instr[$i],$iNS,$iID,$o);

                } elseif ($instr[$i][0] == 'header'){

                    // Set header level to current section level + header level

                    $this->_convert_header($renderer,$instr[$i],$clevel,$o);

                } elseif ($instr[$i][0] == 'section_open'){

                    // The same for sections

                    $level = $instr[$i][1][0] + $clevel;

                    if ($level > 5) {

                        $level = 5;

                    }

                    $instr[$i][1][0] = $level;

                }
            }

            // Split long lines?

            if($o['split_too_long'] && $instr[$i][0] == 'code') {

                $instr[$i][1][0] = wordwrap($instr[$i][1][0],70,"\n");

            }

        }

        // If its the document start, cut off the document information

        if ($instr[0][0] == 'document_start') {

            return array_slice($instr, 1, -1);

        } else {

            return $instr;

        }

    }

    /*
     * convert header of given instruction
     */
    function _convert_header(&$renderer,&$instr,$clevel,$o) {

        global $conf;

        $level = $instr[1][1] + $clevel;

        // If a header level gets "lower" than 5
        if ($level > 5) {

            $level = 5;

        }

        $instr[1][1] = $level;

        // Number headers

        if($o['numbers']) {

            // Number in front of header
            $number = "";

            // Reset lower levels
            for($x = $level+1; $x <= 5; $x++) {
                $o['header_count'][$x] = 0;
            }

            // Raise this level by 1
            $o['header_count'][$level]++;

            // If the level is high (1=high)

            if($level <= $o['numbering_depth']) {
                //build the number
                for($x = 1; $x <= $level; $x++) {
                    $number .= $o['header_count'][$x] . ".";
                }
            }

            //save
            $instr[1][0] = $number . " " . $instr[1][0];
        }

        // add TOC items
        if ($level >= $conf['toptoclevel'] && $level <= $conf['maxtoclevel']){

            $text = $instr[1][0];
            $header_id  = $renderer->_headerToLink($text, 'true');

            //add item to TOC
            $renderer->toc[] = array(
                'hid'   => $header_id,
                'title' => $text,
                'type'  => 'ul',
                'level' => $level-$conf['toptoclevel']+1,
            );

        }
    }

    /*
     * Convert link of given instruction
     */
    function _convert_link(&$renderer, &$instr, $iNS, $id, $o) {

        // Relative subnamespace
        if ($instr[1][0]{0} == '.'){

            // Build complete address
            $instr[1][0] = $iNS.':'.substr($instr[1][0], 1);

        } else if (strpos($instr[1][0],':') === false) {
            //relative link

            $instr[1][0] = $iNS.':'.$instr[1][0];
        }

        // link to another page, but within our included namespace
        // if the link starts with our namespace

        if(strpos($instr[1][0], $o['start_ns']) === 0) {

            //if the link is inside the included depth of pages
            if($o['depth'] === 0 ||
                substr_count($o['start_ns'],":") + $o['depth'] >=
                    substr_count($instr[1][0],":")
            ) {

                // Convert to internal link
                if(strpos($instr[1][0],"#") !== false) {
                    // Get last part a:b#c -> c
                    $levels = preg_split("/#/",$instr[1][0]);

                    // $id we are in atm + # + id of anchor
                    $instr[1][0] = $id . "#" . $levels[sizeof($levels)-1];
                } else {

                    // a:b:c

                    /**
                     * $id we are in atm + # + full id of page to find(->
                     * unique id) not possible to use a:b:c for id --> : -> _
                     */

                    $instr[1][0] = $id . "#" . str_replace(
                        ":",
                        "_",
                        $instr[1][0]
                    );

                    // If page-link has no name

                    if(empty($instr[1][1])) {

                        // TODO: get the real name of the page :(

                        $name = preg_split("/_/",$instr[1][0]);

                        // Last part of id -> name

                        $instr[1][1] = $name[sizeof($name)-1];
                    }
                }
            }
        }
    }

    /**
     * Remove TOC, section edit buttons and tags
     */
    function _cleanXHTML($xhtml){

        $replace  = array(
            '!<div class="toc">.*?(</div>\n</div>)!s' => '', // remove TOCs
            '#<!-- SECTION \[(\d*-\d*)\] -->#e'       => '', // remove section edit buttons
            '!<div id="tags">.*?(</div>)!s'           => ''  // remove category tags
        );

        $xhtml  = preg_replace(
            array_keys($replace),
            array_values($replace),
            $xhtml
        );

        return $xhtml;
    }
}

/*
* evaluate the files presented by search
*
* modify $data according to our wishes
*/
function composer_search_index(&$data, $base, $file, $type, $lvl, $opts) {

    // include in result ?
    $return = true;

    // Directories
    if ($type == 'd'){
        //if max-level is reached, don't return them
        if ($opts['level'] == $lvl) {

            $return = false;

        }

        //add the directory name to list of blocked files
        //so that file with same name will not be included
        $data['blocked'][] = $file . ".txt";

        //if we don't want namespace-nodes in the resultset
        if ($opts['nons']) return $return;

    } elseif($type == 'f' && !preg_match('#\.txt$#',$file)){
        // Don't add files, that end in txt
        return false;
    }

    $id = pathID($file);

    // Check hiddens
    if($type == 'f' && isHiddenPage($id)){
        return false;
    }

    // Check ACL (for namespaces too)
    if(auth_quickaclcheck($id) < AUTH_READ){
        //we are not allowed to read
        return false;
    }

    //check if this files was blocked by us
    if($type == 'f' && in_array($file,$data['blocked'])){
        return false;
    }

    // add the start level(ns we build) to the current level
    $lvl += $data['start_lvl'];

    //pack it up
    $data[]=array( 'id'    => $id,
        'type'  => $type,	//which type is it ?
        'level' => $lvl,	//show on which level ?
        'open'  => $return,	//open it ?
    );

    return $return;

}

/**
 * User defined sort function to sort by level, than by directory,
 * than by start.txt and then by filename
 *
 * @param $a Array left side sort argument
 * @param $b Array right side sort argument
 */

function composer_sort_filedata($a, $b) {

    global $conf;

    // Sort by level

    if ($a["level"] < $b["level"]) {

        return -1;

    } else if ($a["level"] > $b["level"]) {

        return 1;

    } else {

        // Level is the same. Sort by directories

        if (($a["type"] == "d") and ($b["type"] == "f")) {

            return -1;

        } else if (($a["type"] == "f") and ($b["type"] == "d")) {

            return 1;

        } else if (($a["type"] == "d") and ($b["type"] == "d")) {

            // Both are directories. Sort by id

            return strcmp($a["id"], $b["id"]);

        } else {

            // Both are files. Sort by start.txt

            if (stristr($a["id"], $conf["start"]) &&
                !stristr($b["id"], $conf["start"])
            ) {

                return -1;

            } else if (!stristr($a["id"], $conf["start"]) &&
                stristr($b["id"], $conf["start"])
            ) {

                return 1;

            } else {

                // No page is a start page or both, sort by id

                return strcmp($a["id"], $b["id"]);

            }

        }
    }

}