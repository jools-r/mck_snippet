<?php
// ----------------------------------------------------
// Admin side plugin
// ----------------------------------------------------
if (txpinterface == 'admin') {
    add_privs('mck_snippet', '1,2,3,4,5');
    add_privs('mck_snippet.edit', '1,2,3');
    add_privs('mck_snippet.edit.own', '1,2,3,4');
    register_tab('content', 'mck_snippet', "Snippets");
    register_callback('mck_snippets_page', 'mck_snippet');
    // Insert front-end multi-edit facility
    register_callback("mck_snippets_install", "plugin_lifecycle.mck_snippet");
    global $event;
    if ($event=='mck_snippet') {
        register_callback('mck_snippets_head', 'admin_side', 'head_end');
    }

    // Ajax call for admin
    if (gps('mck_snippet_head')) {
        mck_snippets_head();
    }
}

// Register tags
//------------------------------------------------------
if (class_exists('\Textpattern\Tag\Registry')) {
   Txp::get('\Textpattern\Tag\Registry')
       ->register('mck_snippet')
       ->register('mck_snippet_body')
       ->register('mck_snippet_title')
       ->register('mck_snippet_script')
       ->register('mck_snippet_zone')
       ;
}

// Install table and privs
//------------------------------------------------------
function mck_snippets_install($ev, $st)
{
    global $plugins,$plugins_ver,$txpcfg;

    if ($st=="installed") {

    // Check if mck_snippet is already installed
        if (mysqli_num_rows(safe_query('SHOW TABLES LIKE "'.PFX.'mck_snippet"'))==1) {
            // If snipID is not present, the plugin version is less than 1.6
            if (mysqli_num_rows(safe_query('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA="'.$txpcfg['db'].'" AND TABLE_NAME = "mck_snippet" AND COLUMN_NAME = "snipID"'))==0) {
                // Upgrade existing table from 1.5 to 1.7
                safe_alter('mck_snippet', "
                    CHANGE ID snipID               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    CHANGE Title snipTitle         VARCHAR(255) CHARACTER SET utf8 NOT NULL,
                       ADD snipTitle_url           VARCHAR(255) NOT NULL,
                    CHANGE Body snipBody           MEDIUMTEXT CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
                    CHANGE Body_html snipBody_html MEDIUMTEXT CHARACTER SET utf8 NOT NULL,
                    CHANGE Textile snipTextile     INT(2) NOT NULL DEFAULT '1',
                    CHANGE LastMod snipLastMod     DATETIME NOT NULL,
                    CHANGE Status snipStatus       VARCHAR(32) NOT NULL,
                    CHANGE AuthorID snipAuthorID   VARCHAR(64) CHARACTER SET utf8 NOT NULL,
                    CHANGE Lang snipForm           VARCHAR(16) NULL,
                       ADD snipZone                VARCHAR(255) NULL,
                       ADD snipOrder               INT(2) NULL,

                      DROP PRIMARY KEY, ADD PRIMARY KEY ( snipID ),
                      DROP INDEX searching, ADD FULLTEXT `searching` (`snipBody`)
                ");
            } elseif (mysqli_num_rows(safe_query('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA="'.$txpcfg['db'].'" AND TABLE_NAME = "mck_snippet" AND COLUMN_NAME = "snipTitle_url"'))==0) {
                // Upgrade existing table from 1.6 to 1.7
                safe_alter('mck_snippet', "
                       ADD snipTitle_url     VARCHAR(255) NOT NULL,
                    CHANGE snipLang snipForm VARCHAR(16)      NULL,
                       ADD snipZone          VARCHAR(255)     NULL,
                       ADD snipOrder         INT(2)           NULL
                ");
            }
        } else {
            // Install new table 1.7
            safe_create('mck_snippet', "
                snipID        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                snipTitle     VARCHAR(255)     NOT NULL,
                snipTitle_url VARCHAR(255)     NOT NULL,
                snipBody      MEDIUMTEXT       NOT NULL,
                snipBody_html MEDIUMTEXT       NOT NULL,
                  snipTextile   INT(2)           NOT NULL DEFAULT '1',
                  snipLastMod   DATETIME         NOT NULL,
                  snipStatus    VARCHAR(32)      NOT NULL,
                  snipAuthorID  VARCHAR(64)      NOT NULL,
                  snipForm      VARCHAR(16)          NULL,
                  snipZone      VARCHAR(255)         NULL,
                  snipOrder     INT(2)               NULL,

                  PRIMARY KEY (snipID),
                  FULLTEXT `searching` (`snipBody`)
            ");
        }
    }

    if ($st=="deleted") {
        safe_drop('mck_snippet');
    }
}
// Evaluate steps and call function
//------------------------------------------------------
function mck_snippets_page()
{
    global $step,$mck_snippet_vars;

    // Define mck_snippets_vars
    $GLOBALS['mck_snippet_vars'] = array('snipID', 'snipTitle','snipTitle_url', 'snipBody','snipBody_html','snipTextile','snipLastMod','snipStatus','snipAuthorID','snipForm','snipZone','snipOrder','edit_method','selected','hidden','http_referer');

    // Available steps
    $available_steps = array(
            'mck_snippets_list'       => false,
            'mck_snippets_edit'       => false,
            'mck_snippets_save'       => true,
            'mck_snippets_delete'     => true,
            'link_change_pageby'      => true,
            'mck_snippets_multi_edit' => true,
            'mck_snippet_status'      => true
        );

    // Check if step is an available step else show list
    if ($step && bouncer($step, $available_steps)) {
        $step();
    } else {
        mck_snippets_list();
    }
}
// Layout borrowed from txp 4.5 $/textpattern/lib/txp_link.php
//------------------------------------------------------
function mck_snippets_list($message='', $edit_id='')
{
    global $prefs,$event,$step,$statuses;

    pagetop(gTxt('mck_snippet_tab'), $message);
    //create header and button to add snippet
    echo '<h1 class="txp-heading">'.gTxt('mck_snippet_tab').'</h1>';
    echo '<div id="'.$event.'_control" class="txp-control-panel">';
    if (has_privs('mck_snippet.edit')) {
        echo graf(
            sLink('mck_snippet', 'mck_snippets_edit', gTxt('mck_snippet_add')),
            ' class="txp-buttons"'
        );
    }
    echo '</div>';
    $total = getCount('mck_snippet', '1');
    //if there is no results
    if ($total < 1) {
        echo n.graf(gTxt('mck_snippet_no'), ' class="indicator"').'</div>';
        return;
    }
    // Deprecated in 1.7
    // $statuses = array(1 => gTxt('draft'),2 => gTxt('hidden'),3 => gTxt('pending'),4 => gTxt('live'),5 => gTxt('sticky'));

    // Search snippets
    $gpszone=gps('zone');

    $rs=safe_rows_start('*', 'mck_snippet', ((!empty($gpszone)?'snipZone="'.$gpszone.'"':'1=1')).' ORDER BY snipOrder');
    // Set async_params for visible snippet
    // Example of use in 1.7
    $async_params = array(
            'step' => 'mck_snippets_ajax_edit', // This call multiedit function
            'thing' => 'status',         // Some response handlers may need more context which may be put in 'thing' and 'property'.
            'property' => ''    // We just leave them empty here, and we could omit 'thing' and 'property' as well as the core uses defaults.
        );
    if ($rs) {
        // Initialize div and table
        echo n.'<div id="'.$event.'_container" class="txp-container">';
        echo n.n.'<form action="index.php" id="mck_snippets_form" class="multi_edit_form async" method="post" name="longform">',
        n.'<div class="txp-listtables">'.
        n.startTable('', '', 'txp-list').
        n.'<thead>'.// insert table headers
        assHead(fInput('checkbox', 'select_all', 0, '', '', '', '', '', 'select_all'), 'id', gtxt('article_modified'), gtxt('title'), gtxt('body'), gTxt('mck_snippet_zone'), gTxt('author'), gTxt('visible'), gTxt('mck_snippet_code')).
        n.'</thead>';
        echo '<tbody>';
        while ($s = nextRow($rs)) {
            echo tr(
              n.td(fInput('checkbox', 'selected[]', $s['snipID']), '', 'multi-edit').
            n.td($s['snipID']).
            n.td($s['snipLastMod']).
            n.td('<a href="?event=mck_snippet&amp;step=mck_snippets_edit&amp;snipID='.$s['snipID'].'">'.$s['snipTitle'].'</a>').
            n.td(substr($s['snipBody'], 0, 40)).
            n.td('<a href="?event=mck_snippet&zone='.$s['snipZone'].'">'.$s['snipZone'].'</a>').
            n.td($s['snipAuthorID']).
            //deprecated in 1.7
            //n.td('<a href="?event=mck_snippet&amp;step=mck_snippets_multi_edit&amp;edit_method=status&amp;Status='.$s['snipStatus'].'&amp;ID='.$s['snipID'].'">'.$statuses[$s['snipStatus']].'</a>').
            n.td(asyncHref(yes_no($s['snipStatus']), array('step'=>'mck_snippet_status','thing'=>$s['snipID']))).
            n.td('<input readonly="readonly" class="edit" value=\'&lt;txp:mck_snippet title="'.$s['snipTitle'].'"/&gt;\' style="width:95%;"/>'),
              ' id="snippet_'.$s['snipID'].'"'
          ).n;
        }
        // Set methods for multiedit form
        // fInput($type,$name,$value,$class='',$title='',$onClick='',$size='',$tab='',$id='',$disabled = false,$required = false)
        $methods= array('deleted' => gTxt('delete'),    //'edit' => gTxt('edit'),
        );
        echo '</tbody>'.
            n. endTable().
            n. '</div>'.
        n.multi_edit($methods, 'mck_snippet', 'mck_snippets_multi_edit').
            n. tInput().
            n. '</form>'.
            n. '</div>';
    }
}
// Create new or edit existing snippets
//------------------------------------------------------
function mck_snippets_edit($ID='', $message='')
{
    global $mck_snippet_vars, $event, $step, $txp_user,$prefs;

    pagetop(gTxt('mck_snippet_tab'), $message);
    echo '<div id="'.$event.'_container" class="txp-container">';
    extract(array_map('assert_string', gpsa($mck_snippet_vars)));
    $snipID=(!empty($ID))?$ID:$snipID;
    //check if is new or edit
    $is_edit = ($snipID && $step == 'mck_snippets_edit');
    $rs = array();
    if ($is_edit) {
        $id = assert_int($snipID);
        $rs=safe_row('*', 'mck_snippet', 'snipID='.$id);
        if ($rs) {
            extract($rs);
            if (!has_privs('mck_snippet.edit') && !($snipAuthorID == $txp_user && has_privs('mck_snippet.edit.own'))) {
                mck_snippets_list(gTxt('restricted_area'));
                return;
            }
        }
    }
    $gpszone=gps('zone');

    $snipZone=(!empty($gpszone))?$gpszone:$snipZone;
    if (has_privs('mck_snippet.edit') || has_privs('mck_snippet.edit.own')) {
        $caption = gTxt(($is_edit) ? 'edit' : 'create_new');
        echo form(
            '<div class="txp-edit">'.n.
            hed($caption, 2).n.
            inputLabel('snipTitle', fInput('text', 'snipTitle', $snipTitle, '', '', '', INPUT_REGULAR, '', 'snipTitle'), 'title').n.
            inputLabel('snipBody', '<textarea id="snipBody" name="snipBody" cols="'.INPUT_REGULAR.'" rows="'.INPUT_SMALL.'">'.txpspecialchars($snipBody).'</textarea>', 'body', 'body', '', '').n.
            inputLabel('snipTextile', pref_text('snipTextile', (($is_edit)?$snipTextile:$prefs['use_textile']), 'textile'), 'mck_snippet_markup').n.
            inputLabel('snipForm', mck_form_pop($snipForm, 'override-form'), 'form_name').n.
            inputLabel('snipZone', fInput('text', 'snipZone', $snipZone, '', '', '', INPUT_REGULAR, '', 'snipZone'), 'mck_snippet_zone').n.
            graf(fInput('submit', '', gTxt('save'), 'publish')).
            pluggable_ui('mck_snippets_ui', 'extend_snippets_form', '', $rs).n.
            sInput('mck_snippets_multi_edit').
            eInput('mck_snippet').
            (($is_edit)?hInput('snipID', $id).hInput('edit_method', 'updated'):hInput('edit_method', 'saved')).
            ((!preg_match("/\b\/textpattern\/index.php\b/i", $_SERVER["HTTP_REFERER"]))?hInput('http_referer', $_SERVER["HTTP_REFERER"]):'').
            '</div>',
            '',
            '',
            'post',
            'edit-form',
            '',
            'link_details'
        );
    }
    echo '</div>';
}
//------------------------------------------------------
function mck_snippets_multi_edit()
{
    global $prefs,$txp_user,$mck_snippet_vars;
    $snip = mck_textile_easy(gpsa($mck_snippet_vars));
    // mck_check_url_title  --------------
    //print_r($snip);
    switch ($snip['edit_method']) {
    case 'edit':
      return mck_snippets_edit($snip['selected'][0]);
      break;
    case 'deleted':
      foreach ($snip['selected'] as $id) {
          $id = assert_int($id);
          if (!safe_delete('mck_snippet', "snipID = $id")) {
              return gTxt('error');
          }
      }
      break;
    case 'updated':
      safe_update('mck_snippet', "snipTitle='$snip[snipTitle]',snipTitle_url='$snip[snipTitle_url]',snipBody='$snip[snipBody]',snipBody_html='$snip[snipBody_html]',snipTextile=$snip[snipTextile],snipLastMod=now(),snipForm='$snip[snipForm]',snipZone='$snip[snipZone]'", "snipID=$snip[snipID]");
      break;
    case 'saved':
      safe_insert('mck_snippet', "snipTitle='$snip[snipTitle]',snipTitle_url='$snip[snipTitle_url]',snipLastMod=now(),snipStatus=1,snipBody='$snip[snipBody]',snipBody_html='$snip[snipBody_html]',snipTextile=$snip[snipTextile],snipAuthorID='$txp_user',snipForm='$snip[snipForm]',snipZone='$snip[snipZone]'");
      if ($snip['http_referer']) {
          header('Location:'.$snip['http_referer']);
      }
      break;
  }
    mck_snippets_list('Snippets '.gtxt($snip['edit_method']));
}

/**
  * AJAX response handler for the mck_snippet_ajax_edit step
*/
function mck_snippet_status()
{
    //grab the values and check if are string
    extract(array_map('assert_string', gpsa(array('thing', 'value'))));
    $change = ($value == gTxt('yes')) ? 0 : 1;
    safe_update('mck_snippet', "snipStatus=$change", 'snipID="'.doSlash($thing).'"') ;
    echo gTxt($change ? 'yes' : 'no');
}

/**
  * Prepare Title and Body for storage data
*/
function mck_textile_easy($incoming)
{
    global $txpcfg;
    // For ajax compatibility use IF
    if (!empty($incoming['snipTitle'])) {
        $incoming['snipTitle_url'] = strtolower(sanitizeForUrl($incoming['snipTitle']));
    }

    // For ajax compatibility use IF
    if (!empty($incoming['snipBody'])) {
        include_once txpath.'/lib/classTextile.php';
        $textile = new Textile();
        switch ($incoming['snipTextile']) {
      case 0:
    $incoming['snipBody_ajax'] = trim($incoming['snipBody']);
    break;
      case 1:
    $incoming['snipBody_ajax'] = $textile->TextileThis($incoming['snipBody']);
    break;
      case 2:
    $incoming['snipBody_ajax'] = nl2br(trim($incoming['snipBody']));
    break;
    }
        $incoming['snipBody']=doSlash($incoming['snipBody']);
        // Use doSlah() for DB saving
        $incoming['snipBody_html'] = doSlash($incoming['snipBody_ajax']);
    }
    return $incoming;
}

/**
  * Prepare form list for snippet edit
*/
function mck_form_pop($form, $id)
{
    $arr = array(' ');
    $rs = safe_column('name', 'txp_form', "type = 'misc' order by name");
    if ($rs) {
        return selectInput('snipForm', $rs, $form, true, '', $id);
    }
}

/**
  * Output Js on admin end
*/
function mck_snippets_head()
{
    $act=gps('mck_snippet_head');
    switch ($act) {
    case 'css':
      header("Content-type: text/css");
      ?>
#mck_snippets_form tbody tr:hover{background:#eee;cursor:move;}
      <?php
      die();
    case 'js':
      header("Content-type: text/javascript");
      ?>
$(document).ready(function(){
  $('#mck_snippets_form tbody').sortable({
      update : function (e,ui) {
        var serial = $(this).sortable('serialize');
        //var tr=ui.item;
        $.ajax({
      url: "./?mck_snippet_head=ajax",
      type: "post",
      data: serial,
      error: function(){
        alert("theres an error with AJAX");
      },
          success : function(resp) {
       // alert(resp);
},
    });
      }
    });
});
      <?php
      die();
    case 'ajax':
    global $theme;
    $snip=gps('snippet');
    for ($i = 0; $i < count($snip); $i++) {
        // $out[]="UPDATE mck_snippet SET snipOrder=".$i." WHERE snipID='".$snip[$i]."'";
        safe_update('mck_snippet', 'snipOrder='.$i, 'snipId='.$snip[$i]);
        // mysqli_query("UPDATE `menu` SET `sort`=" . $i . " WHERE `id`='" . $menu[$i] . "'") or die(mysql_error());
    }

    // With new feature show message!
    send_script_response(
        $theme->announce_async(array(
            gtxt('mck_snippet_reorganize'), false
        ))
    );
    //E_ERROR
    //E_WARNING
      // echo join($out);
    die();
    default:
      if (gps('zone')) {
          echo '<link rel="stylesheet" type="text/css" media="screen" href="./?mck_snippet_head=css" />'.n.
  '<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.10.1/jquery-ui.min.js"></script><script type="text/javascript" src="./?mck_snippet_head=js"></script>';
      }
  }
}

// -------------------------------------------------------------
function mck_check_url_title($url_title)
{
    // Check for blank or previously used identical url-titles
    if (strlen($url_title) === 0) {
        return gTxt('url_title_is_blank');
    } else {
        $url_title_count = safe_count('mck_snippet', "snipTitle_url = '$url_title'");
        if ($url_title_count > 1) {
            return gTxt('url_title_is_multiple', array('{count}' => $url_title_count));
        }
    }
    return '';
}


// ----------------------------------------------------
// Public side plugin
// ----------------------------------------------------
if (txpinterface == 'public') {
    if (gps('mck_snippet_script')) {
        mck_snippet_script();
    }
}

/**
 * Displays list of snippet
 * @param array $atts
 * @param string $atts[wraptag] HTML Wraptag.
 * @param string $atts[wrap_id] Wraptag' HTML id.
 * @param string $atts[class] Wraptag's HTML class.
 * @param string $atts[form] HTML snippet form
 * @param string $atts[break] HTML break
 * @param string $atts[breakclass] HTML break class
 * @param Boolean $atts[empty] Set if there is some text into a empty snippet or not
 * @return string HTML markup
 * <code>
 *        <txp:mck_snippet_zone zone="zone" wraptag="htmlwrap" class="wraptag_class" wrap_id="wraptag_id" form="form">
 * </code>
 */
function mck_snippet_zone($atts)
{
    global $thissnippet,$plugins;

    extract(lAtts(array(
    'name'=>'',
    'wraptag'=>'div',
    'class'=>'',
    'form'=>'',
    'break'=>'',
    'breakclass'=>'',
    'empty'=>gtxt('mck_snippet_add_content'),
    'editable'=>0,
  ), $atts));
    // Check minimum requirements
    if (empty($name)) {
        return gtxt('mck_snippet_no_zone');
    }
    // Check plugin
    if (!in_array('mck_login', $plugins) && $editable) {
        return gTxt('plugin_load_error').' mck_login';
    }

    $rs=safe_rows('*', 'mck_snippet', 'snipZone="'.$name.'" AND snipStatus=1 ORDER BY snipOrder');
    if ($rs) {
        foreach ($rs as $s) {
            // Set editable and check login
            if ($editable && mck_login(true)) {
                $s['snipEditable']=true;
            }

            // Set global var
            $thissnippet=$s;
            if ($form) {
                $out[]=parse_form($form);
            } elseif ($s['snipForm']) {
                $out[]=parse_form($s['snipForm']);
            } else {
                $out[]=(@$s['snipEditable'])?'<div class="mck_snippet_editable" data-snipBody="'.$s['snipID'].'">'.$s['snipBody_html'].'</div>':$s['snipBody_html'];
            }
        }
        return doWrap($out, $wraptag, $break, $class.' mck_snippet_zone', $breakclass, ' data-snipZone="'.$name.'"');
    }
    // Leave empty
    return doWrap(array((($empty==1)?'&nbsp;':$empty)), $wraptag, $break, $class.' mck_snippet_zone', $breakclass, ' data-snipZone="'.$name.'"');
}


/**
 * Displays single snippet
 * @param array $atts
 * @param string $atts[id] Snippet id.
 * @param string $atts[title] Snippet univoqe title.
 * @param string $atts[wraptag] HTML Wraptag.
 * @param string $atts[wrap_id] Wraptag' HTML id.
 * @param string $atts[class] Wraptag's HTML class.
 * @param string $atts[form] HTML snippet form
 * @param boolean $atts[editable] alloe edit in place yes:1/no:0 default:0
 * @return string HTML markup
 * <code>
 *        <txp:mck_snippet id="id" title="snippet_title" wraptag="htmlwrap" class="wraptag_class" wrap_id="wraptag_id">
 * </code>
 */
function mck_snippet($atts)
{
    global $thissnippet,$plugins;

    extract(lAtts(array(
    'id'=>'',
    'title'=>'',
    'wraptag'=>'',
    'class'=>'',
    'form'=>'',
    'wrap_id'=>'',
    'editable'=>0,
  ), $atts));
    // Check minimum requirements
    if (empty($id) && empty($title)) {
        return gtxt('mck_snippet_need');
    }
    // Check plugin
    if ($editable==1 && !in_array('mck_login', $plugins)) {
        return gTxt('plugin_load_error').' mck_login';
    }

    // Check before title after ID (ID is better)
    if (!empty($title)) {
        $where='snipTitle="'.$title.'" AND snipStatus=1 LIMIT 1';
    }

    if (!empty($id)) {
        $where='snipID="'.$id.'" AND snipStatus=1 LIMIT 1';
    }

    $thissnippet=safe_row('*', 'mck_snippet', $where);

    // Set editable and check login
    if ($editable && mck_login(true)) {
        $thissnippet['snipEditable']=true;
    }

    if ($thissnippet) {
        // array('title'=>$snipp['snipTitle'],'body'=>$snipp['snipBody'],'posted'=>$snipp['snipLastMod'],'authorid'=>$snipp['snipAuthorID']);
        // Check before form set on database after predefined
        if (!empty($thissnippet['snipForm'])) {
            return parse_form($thissnippet['snipForm']);
        }
        if (!empty($form)) {
            return parse_form($form);
        }
        return doTag(((@$thissnippet['snipEditable'])?'<div class="mck_snippet_editable" data-snipBody="'.$thissnippet['snipID'].'">'.$thissnippet['snipBody_html'].'</div>':$thissnippet['snipBody_html']), $wraptag, $class, '', $wrap_id);
    }
}


/**
 * Displays snippet title
 * @param array $atts
 * @param int $atts[no_widow] Override prefs.
 * @return string Title
 * <code>
 *        <txp:mck_snippet_title no_widow="1" />
 * </code>
 */
function mck_snippet_title($atts)
{
    global $thissnippet;
    extract(lAtts(array(
    'no_widow' => @$prefs['title_no_widow'],
   ), $atts));

    $t = escape_title($thissnippet['snipTitle']);
    if ($no_widow) {
        $t = noWidow($t);
    }
    if (@$thissnippet['snipEditable']) {
        return '<div class="mck_snippet_editable" data-snipTitle="'.$thissnippet['snipID'].'">'.$t.'</div>';
    }

    return $t;
}

/**
 * Displays snippet body
 * @return string Html body
 * <code>
 *        <txp:mck_snippet_body />
 * </code>
 */
function mck_snippet_body()
{
    global $thissnippet;

    if (@$thissnippet['snipEditable']) {
        return '<div class="mck_snippet_editable" data-snipBody="'.$thissnippet['snipID'].'">'.$thissnippet['snipBody_html'].'</div>';
    }

    return $thissnippet['snipBody_html'];
}

/**
 * Return css and js link for frontend snippet edit
 * @return string Html body
 * <code>
 *        <txp:mck_snippet_script />
 * </code>
 */
function mck_snippet_script()
{
    global $plugins;
    //using frontend edit require mck_login
    if (!in_array('mck_login', $plugins)) {
        return gTxt('plugin_load_error').' mck_login';
    }

    //preserve invalid access if not logged in
    if (!mck_login(true)) {
        return;
    }

    $act=gps('mck_snippet_script');
    switch ($act) {
    case 'css':
      header("Content-type: text/css");
      ?>
.mck_snippet_zoneHover{border:1px dashed #000;}
.mck_snippet_back{position:absolute;top:-10px;left:5px;text-align:center;}
.mck_snippet_editableNew,
.mck_snippet_editableEdit{text-align:center;background:#000;color:#fff;}
.mck_snippet_editable{cursor:text;}
.mck_snippet_editableHover{border:0.2px dotted #000;}
.mck_snippet_editBox{width:100%;}
.mck_snippet_ajax_load{font-size:0.75em;color:#000;background:#fff;}
.mck_snippet_editableDiscard,
.mck_snippet_editableSave{text-align:center;background:#000;color:#fff;}
      <?php
      die();
    case 'js':
      header("Content-type: text/javascript");
      ?>
$(document).ready(function () {
    $(".mck_snippet_zone").mck_snippet_zone();
    $(".mck_snippet_editable").mck_snippet_editable();
});

$.fn.mck_snippet_editable = function (options) {
    // define some options with sensible default values
    // - hoverClass: the css classname for the hover style
    options = $.extend({
        hoverClass: 'mck_snippet_editableHover'
    }, options);

    return $.each(this, function () {
        // define self container
        var self = $(this);
        var $obj = {};
        self.old = self.html();
        self.bind('dblclick', function () {
            $obj.snipBodyID = self.attr('data-snipBody');
            $obj.snipTitleID = self.attr('data-snipTitle');
            self.html('<span class="mck_snippet_ajax_load">Loading..</span>');
            if ($obj.snipTitleID) {
                // create a value property to keep track of current value
                //self.value is equal at snipTitle from DB.
                self.value = self.text();
                $obj.html = '<input maxlength="255" class="mck_snippet_editBox" type="text" value="' + $.trim(self.value) + '">';
                $obj.find = 'input';
            }
            if ($obj.snipBodyID) {
                $obj.act = 'get';
                //get the snipBody_html value from DB.
                $.ajax({
                    async: false,
                    type: "POST",
                    url: "?mck_snippet_script=ajax",
                    data: {
                        'ajaxobj': $obj
                    },
                    success: function (data) {
                        self.value = data;
                    },
                    error: function () {
                        self.value = 'Get error';
                    }
                });
                $obj.html = '<textarea class="mck_snippet_editBox" cols="30" rows="10">' + $.trim(self.value) + '</textarea>';
                $obj.find = 'textarea';
            }
            var ajaxbtn = '<span class="mck_snippet_front"><a class="mck_snippet_editableSave" href="#"><?php echo gtxt('save'); ?></a> <a class="mck_snippet_editableDiscard"  href="#"><?php echo gtxt('mck_snippet_discard'); ?></a></span>';
            self.html($obj.html + ajaxbtn)
                .find('a')
                .bind('click', function (e) {
                e.preventDefault;
                if ($(this).hasClass('mck_snippet_editableSave')) {
                    $obj.act = 'save';
                    $obj.new = self.find($obj.find).val();
                    self.html('<span class="mck_snippet_ajax_load">Loading..</span>');
                    $.ajax({
                        async: false,
                        type: "POST",
                        url: "?mck_snippet_script=ajax",
                        data: {
                            'ajaxobj': $obj
                        },
                        success: function (data) {
                            self.value = data;
                        },
                        error: function () {
                            self.value = 'Save error';
                        }
                    });
                    self.html(self.value);
                } else {
                    self.html(self.old);
                }

            })
                .focus();
        })
        // on hover add hoverClass, on rollout remove hoverClass
        .hover(

        function () {
            self.addClass(options.hoverClass);
        },

        function () {
            self.removeClass(options.hoverClass);
        });
    });
}

$.fn.mck_snippet_zone = function (options) {
    // define some options with sensible default values
    // - hoverClass: the css classname for the hover style
    options = $.extend({
        hoverClass: 'mck_snippet_btn',
    }, options);
   return $.each(this,function () {
        var zone = $(this).attr('data-snipZone');
        var btn = '<span class="mck_snippet_back"><a class="mck_snippet_editableNew" href="<?php echo hu; ?>textpattern/index.php?event=mck_snippet&amp;step=mck_snippets_edit&amp;zone=' + zone + '"><?php echo gtxt('create_new'); ?></a> <a class="mck_snippet_editableEdit" href="<?php echo hu; ?>textpattern/index.php?event=mck_snippet&amp;zone=' + zone + '"><?php echo gtxt('edit'); ?></a></span>';
        $(this).hover(
        function () {
            $(this).css('position','relative').addClass('mck_snippet_zoneHover').append(btn);
        },

        function () {
            $(this).css('position','static').removeClass('mck_snippet_zoneHover').find('.mck_snippet_back').remove();
        });
    });
};
      <?php
      die();
    case 'ajax':
    $data = ps('ajaxobj');
    //get data snipBody_htmlcodice
    if ($data['act']=='get' && $data['snipBodyID']) {
        die(safe_field('snipBody', 'mck_snippet', 'snipID='.$data['snipBodyID']));
    }

    //save data
    if ($data['act']=='save') {
        if (!empty($data['snipBodyID'])) {
            //grab textile for body
            $data['snipTextile']=safe_field('snipTextile', 'mck_snippet', 'snipID='.$data['snipBodyID']);
            //set variable name
            $data['snipBody']=$data['new'];
            //parse texile
            $snip = mck_textile_easy($data);
            //save new value
            safe_update('mck_snippet', "snipBody='$snip[snipBody]',snipBody_html='$snip[snipBody_html]',snipLastMod=now()", "snipID=$data[snipBodyID]");
            $out=$snip['snipBody_ajax'];
        }
        if (!empty($data['snipTitleID'])) {
            $data['snipTitle']=$data['new'];
            $snip = mck_textile_easy($data);
            safe_update('mck_snippet', "snipTitle='$snip[snipTitle]',snipTitle_url='$snip[snipTitle_url]',snipLastMod=now()", "snipID=$data[snipTitleID]");
            $out=$snip['snipTitle'];
        }
    }

    die($out);
    default:
      return '<link rel="stylesheet" type="text/css" media="screen" href="?mck_snippet_script=css" />'.n.
  '<script type="text/javascript" src="?mck_snippet_script=js"></script>';
  }
}
