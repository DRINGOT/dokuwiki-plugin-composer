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
        //defaults
        $level = 0;							//how deep ?
        $nons = true;						//namespacenodes ?

        //only match options, no syntax
        $match = substr($match,10,-2);

        //split namespace+level | options
        $match = preg_split('/\|/u', $match, 2);

        //--------NAMESPACE+LEVEL
        //find namespace & level in namespace+level
        if ( preg_match('/(.*)#(\S*)/u',$match[0],$ns_opt)) {
            $ns=$ns_opt[1];				//inside first brackets
            if (is_numeric($ns_opt[2])){
                $level=$ns_opt[2];			//inside 2nd brackets
            }
        }
        //nothin matched -> original string is only namespace
        else{
            $ns=$match[0];
        }
        //--------

        //--------OPTIONS
        //options are sperated by whitespace
        $opts=preg_split('/ /u',$match[1]);

        //include namespace nodes ?
        $nons = in_array('nons',$opts);

        //absolute indentation ?
        $relative = false;
        if(!in_array('absolute',$opts)){
            $relative = substr_count($ns,":")+1;
        }

        //--------NUMBERING ?
        $numbering_depth = 	2;
        $numbers = 			false;

        if(in_array('numbers',$opts)) {
            $numbers = true;

            $temp = array_search('numbers',$opts)+1;
            //if value after numbers is int

            if($temp !== false && isset($opts[$temp])) {
                //depth is value after numbers(if its a int lower 6)
                if(intval($opts[$temp]) < 6) {
                    $numbering_depth = intval($opts[$temp]);
                }
            }
        }
        //--------

        //--------PRINT OPTIMIZATION ?
        $split_too_long = false;
        if(in_array('print',$opts)) {
            $split_too_long = true;	//split too long lines
        }
        //--------

        return array(
            'namespace'=>		$ns,
            'search_options'=>	array('level' => $level,'nons' => $nons),
            'output_options'=>	array(
                'relative'=>		$relative,
                'depth'=>			$level,
                'start_ns'=>		$ns,
                'numbers'=>			$numbers,
                'numbering_depth'=>	$numbering_depth,
                'split_too_long'=>	$split_too_long,
            ),
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

            $data['output_options']['header_count']= &$header_count;

            //get all files in the supplied namespace, with their level
            $file_data=$this->_get_file_data($data,$renderer);

            foreach($file_data as $file) {
                //build the output
                $this->_render_output($renderer,$file,$data['output_options']);
            }
            return true;
        }
        return false;
    }

    /*
          * build the output
          *
          *
          * $data
          * 	0:
          * 		namespace (a:b:c)
          * 	1:
          * 		level:
          * 			how deep shall we build the tree ?
          *
          * 		nons:
          * 			no namespace-nodes (aka directories) ?
          *
          */
    function _get_file_data($data,&$renderer) {
        global $conf;
        global $ID;

        //clean input
        $default = 	array(
            'level'=>0,
            'nons'=>true,
        );
        $options =	array_merge($default,$data['search_options']);
        $ns = 		$data['namespace'];


        //--------BUILD THE NAMESPACE
        //namespace options . or ..
        if($ns == '.') {
            $ns = dirname(
            //replace / with : in $ID string(current location)
                str_replace(':','/',$ID)
            );
            //still . ?
            if ($ns == '.') $ns = '';
        }
        //normal namespace entered
        else {
            $ns = cleanID($ns);		//Dokuwiki cleaner
        }

        //replace : with /
        $ns  = utf8_encodeFN(str_replace(':','/',$ns));
        //--------

        //--------FIND THE FILES
        $file_data = array(
            'start_lvl'=>substr_count($ns,"/"),	//start for indentation
            'blocked'=>array(),					//blocked files
        );

        //find all matching files
        search($file_data,$conf['datadir'],'composer_search_index',$options,"/".$ns);
        //--------

        //clean the file_data from non-files (for safety)
        unset($file_data['start_lvl']);
        unset($file_data['blocked']);

        return $file_data;
    }


    /*
          * Build includes
          * options $o:
          * 	relative:
          *			output file-tree indentation will be absolute according to file level
          *			OR relative to level of namespace-level, value of absolute ist level of namespace
          *			==> false OR 1-999
          *	start_ns:
          *			the namespace we are including (ns entered by the user)
          *	search_depth:
          *			how deep are we searching ?
          *
          */
    function _render_output(&$renderer,$file_data,$o){
        //clean input options
        $default = array(
            'relative'=>		false,
            'start_ns'=>		"",
            'depth'=>			0,
            'header_count'=>	array(),
            'numbers'=>			false,
            'numbering_depth'=>	3,
            'split_too_long'=>	false,
        );
        $o = array_merge($default,$o);

        //file attributes
        $id = 		$file_data['id'];
        $open =		$file_data['open'];

        //build with relative indentation
        if($o['relative']){
            $clevel = $file_data['level'] - $o['relative'];
        }
        //absolute indentation
        else {
            $clevel = 	$file_data['level'];
        }


        //dont open the file
        if(!$open) {
            //only in case of directories and nons on
            return false;
        }

        //convert a:b -> home/data/pages/a/b
        $file    = wikiFN($id);

        //get data(in instructions format) from $file (dont use cache: false)
        $instr   = p_cached_instructions($file, true);

        //page was not empty
        if (!empty($instr)) {
            //fix relative links and lower headers of included pages
            unset($o['relative']);	//we dont need this option for conversion
            $instr   = $this->_convertInstructions($instr, $id, $renderer, $clevel,$o, $ok);

            //--------RENDER
            //renderer information(TOC build / Cache used)
            $info = array();

            //render the instructions to outpu data
            $content = p_render('xhtml', $instr, $info);
            //--------

            //Remove TOC`s, section edit buttons and tags
            $content = $this->_cleanXHTML($content);
        }

        // embed the included page
        $renderer->doc .= '<div class="include">';
        //add an anchor to find start of a inserted page
        $id = str_replace(":","_",$file_data['id']);	//not possible to use a:b:c for id
        $renderer->doc .= "<a name='$id' id='$id'>";
        $renderer->doc .= $content;
        $renderer->doc .= '</div>';
    }



    /*
          * Corrects relative internal links and media and
          * converts headers of included pages to subheaders of the current page
          */
    function _convertInstructions($instr, $incl, &$renderer, $clevel,$o, &$ok){
        global $ID;
        global $conf;

        //clean input options
        $default = array(
            'start_ns'=>		"",
            'depth'=>			0,
            'header_count'=>	array(),
            'numbers'=>			false,
            'numbering_depth'=>	3,
            'split_too_long'=>	false,
        );
        $o = array_merge($default,$o);

        // check if included page is already in output namespace
        $iNS =	getNS($incl);
        $id =	getNS($ID);

        //the content belongs to the original page
        if ($id == $iNS)
            $convert = false;	//just leave as it is
        //the contetn was newly included, and is not original page content
        else {
            $convert = true;	//convert content
        }

        $n = count($instr);
        for ($i = 0; $i < $n; $i++){
            // convert it
            if ($convert) {
                //internal links(links inside this wiki) an relative links
                if((substr($instr[$i][0], 0, 8) == 'internal')){
                    $this->_convert_link($renderer,$instr[$i],$iNS,$id,$o);
                }
                // set header level to current section level + header level
                elseif ($instr[$i][0] == 'header'){
                    $this->_convert_header($renderer,$instr[$i],$clevel,$o);
                }
                // the same for sections
                elseif ($instr[$i][0] == 'section_open'){
                    $level = $instr[$i][1][0]+$clevel;
                    if ($level > 5)
                        $level = 5;
                    $instr[$i][1][0] = $level;
                }
            }//end of convert

            //--------SPLIT TOO LONG LINES ?
            if($o['split_too_long'] && $instr[$i][0]=='code') {
                $instr[$i][1][0] = wordwrap($instr[$i][1][0],70,"\n");
            }
            //--------
        }//end of for loop

        //if its the document start, cut off the first element(document information)
        if ($instr[0][0] == 'document_start')
            return array_slice($instr, 1, -1);
        else
            return $instr;

    }

    /*
          * convert header of given instruction
          */
    function _convert_header(&$renderer,&$instr,$clevel,$o) {
        global $conf;

        $level = $instr[1][1]+$clevel;
        //if a header level gets "lower" than 5
        if ($level > 5)
            $level = 5;

        $instr[1][1] = $level;

        //--------HEADER NUMBER
        if($o['numbers']) {
            //number infornt of header
            $number = "";

            //reset lower levels
            for($x=$level+1;$x<=5;$x++){
                $o['header_count'][$x]=0;
            }

            //raise this level by 1
            $o['header_count'][$level]++;


            //if the level is high (1=high)
            if($level <= $o['numbering_depth']) {
                //build the number
                for($x=1;$x<=$level;$x++) {
                    $number .= $o['header_count'][$x] . ".";
                }
            }

            //save
            $instr[1][0] = $number . " " . $instr[1][0];
        }
        //--------

        // add TOC items
        if ($level >= $conf['toptoclevel'] && $level <= $conf['maxtoclevel']){
            $text = $instr[1][0];
            $header_id  = $renderer->_headerToLink($text, 'true');	//with unique id(true)
            //add item to TOC
            $renderer->toc[] = array(
                'hid'   => $header_id,
                'title' => $text,
                'type'  => 'ul',
                'level' => $level-$conf['toptoclevel']+1,
            );

            $ok = true;
        }
    }

    /*
          * Convert link of given instruction
          */
    function _convert_link(&$renderer,&$instr,$iNS,$id,$o) {
        // relative subnamespace
        if ($instr[1][0]{0} == '.'){
            //build complete address
            $instr[1][0] = $iNS.':'.substr($instr[1][0], 1);
        }
        // relative link
        elseif (strpos($instr[1][0],':') === false) {
            //build complete address
            $instr[1][0] = $iNS.':'.$instr[1][0];
        }

        //link to another page, but within our included namespace
        //if the link starts with our namespace
        if(strpos($instr[1][0],$o['start_ns']) === 0) {
            //if the link is inside the included depth of pages
            if($o['depth']===0 ||
                substr_count($o['start_ns'],":")+$o['depth'] >=
                    substr_count($instr[1][0],":")) {

                //convert to internal link
                if(strpos($instr[1][0],"#") !== false) {
                    //get last part a:b#c -> c
                    $levels = split("#",$instr[1][0]);

                    //$id we are in atm + # + id of anchor
                    $instr[1][0] = $id . "#" . $levels[sizeof($levels)-1];
                }
                //a:b:c
                else {
                    //$id we are in atm + # + full id of page to find(-> unique id)
                    //not possible to use a:b:c for id --> : -> _
                    $instr[1][0] = $id . "#" . str_replace(":","_",$instr[1][0]);

                    //if page-link has no name
                    if(empty($instr[1][1])) {
                        //TODO: get the real name of the page :(
                        $name = split("_",$instr[1][0]);
                        //last part of id -> name
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
        $xhtml  = preg_replace(array_keys($replace), array_values($replace), $xhtml);
        return $xhtml;
    }
}


/*
* evaluate the files presented by search
*
* modify $data according to our wishes
*/
function composer_search_index(&$data,$base,$file,$type,$lvl,$opts){
    //include in result ?
    $return = true;

    //directories
    if($type == 'd'){
        //if max-level is reached, don't return them
        if ($opts['level'] == $lvl) $return=false;

        //add the directory name to list of blocked files
        //so that file with same name will not be included
        $data['blocked'][] = $file . ".txt";

        //if we don't want namespace-nodes in the resultset
        if ($opts['nons']) return $return;
    }
    //files that are no .txt
    elseif($type == 'f' && !preg_match('#\.txt$#',$file)){
        //don't add
        return false;
    }

    $id = pathID($file);

    //check hiddens
    if($type=='f' && isHiddenPage($id)){
        return false;
    }

    //check ACL (for namespaces too)
    if(auth_quickaclcheck($id) < AUTH_READ){
        //we are not allowed to read
        return false;
    }
    //check if this files was blocked by us
    if($type=='f' && in_array($file,$data['blocked'])){
        return false;
    }

    //add the start level(ns we build) to the current level
    $lvl += $data['start_lvl'];

    //if we don't want the namespace-nodes ?
    if ($opts['nons']) {
        //lower the level 1
        $lvl-=1;
    }

    //pack it up
    $data[]=array( 'id'    => $id,
        'type'  => $type,	//which type is it ?
        'level' => $lvl,	//show on which level ?
        'open'  => $return,	//open it ?
    );

    return $return;
}

?>

