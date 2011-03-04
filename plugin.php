<?php
/**
 * CsvImport plugin
 *
 * @copyright  Center for History and New Media, 2008-2011
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt
 * @version    $Id:$
 * @package CsvImport
 * @author CHNM
 **/

define('CSV_IMPORT_DIRECTORY', dirname(__FILE__));
define('CSV_IMPORT_CSV_FILES_DIRECTORY', CSV_IMPORT_DIRECTORY . '/csv_files');
define('CSV_IMPORT_BACKGROUND_SCRIPTS_DIRECTORY', CSV_IMPORT_DIRECTORY 
    . '/background_scripts');

define('CSV_IMPORT_COLUMN_MAP_TAG_CHECKBOX_PREFIX', 'column_map_tag_');
define('CSV_IMPORT_COLUMN_MAP_FILE_CHECKBOX_PREFIX', 'column_map_file_');
define('CSV_IMPORT_COLUMN_MAP_HTML_CHECKBOX_PREFIX', 'column_map_html_');

define('CSV_IMPORT_COLUMN_MAP_ELEMENTS_LIST_PREFIX', 
    'column_map_elements_list_');
define('CSV_IMPORT_COLUMN_MAP_ELEMENTS_DROPDOWN_PREFIX', 
    CSV_IMPORT_COLUMN_MAP_ELEMENTS_LIST_PREFIX . 'dropdown_');
define('CSV_IMPORT_COLUMN_MAP_ELEMENTS_HIDDEN_INPUT_PREFIX', 
    CSV_IMPORT_COLUMN_MAP_ELEMENTS_LIST_PREFIX . 'hidden_input_'); 

add_plugin_hook('install', 'csv_import_install');
add_plugin_hook('uninstall', 'csv_import_uninstall');
add_plugin_hook('config_form', 'csv_import_config_form');
add_plugin_hook('config', 'csv_import_config');
add_plugin_hook('admin_theme_header', 'csv_import_admin_header');
add_plugin_hook('define_acl', 'csv_import_define_acl');

add_filter('admin_navigation_main', 'csv_import_admin_navigation');

/**
 * Install the plugin.
 * 
 * @return void
 */
function csv_import_install()
{    
    $db = get_db();
    
    // create csv imports table
    $db->exec("CREATE TABLE IF NOT EXISTS `{$db->prefix}csv_import_imports` (
       `id` int(10) unsigned NOT NULL auto_increment,
       `item_type_id` int(10) unsigned NOT NULL,
       `collection_id` int(10) unsigned NOT NULL,       
       `csv_file_name` text collate utf8_unicode_ci NOT NULL,
       `status` varchar(255) collate utf8_unicode_ci,
       `error_details` TEXT collate utf8_unicode_ci,
       `item_count` int(10) unsigned NOT NULL,
       `is_public` tinyint(1) default '0',
       `is_featured` tinyint(1) default '0',
       `stop_on_file_error` tinyint(1) default '0',
       `serialized_column_maps` text collate utf8_unicode_ci NOT NULL,
       `added` timestamp NOT NULL default '0000-00-00 00:00:00',
       PRIMARY KEY  (`id`)
       ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");
   
   // create csv imported items table
   $db->exec("CREATE TABLE IF NOT EXISTS `{$db->prefix}csv_import_imported_items` (
      `id` int(10) unsigned NOT NULL auto_increment,
      `item_id` int(10) unsigned NOT NULL,
      `import_id` int(10) unsigned NOT NULL,       
      PRIMARY KEY  (`id`),
      KEY (`import_id`)
      ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");    
       
}

/**
 * Uninstall the plugin.
 * 
 * @return void
 */
function csv_import_uninstall()
{
    // delete the plugin options
    delete_option('csv_import_memory_limit');
    delete_option('csv_import_php_path');
    
    // drop the tables
    $db = get_db();
    $sql = "DROP TABLE IF EXISTS `{$db->prefix}csv_import_imports`";
    $db->query($sql);
    $sql = "DROP TABLE IF EXISTS `{$db->prefix}csv_import_imported_items`";
    $db->query($sql);
    
}

/**
 * Defines the ACL for the reports controllers.
 *
 * @param Omeka_Acl $acl Access control list
 */
function csv_import_define_acl($acl)
{
    // only allow super users and admins to import csv files
    $acl->loadResourceList(array(
        'CsvImport_Index' => array('index', 
        'map-columns', 'undo-import', 
        'clear-history', 'status')
    ));
}

/**
 * Add the admin navigation for the plugin.
 * 
 * @return array
 */
function csv_import_admin_navigation($tabs)
{
    if (get_acl()->isAllowed(current_user(), 'CsvImport_Index', 'index')) {
        $tabs['CSV Import'] = uri('csv-import');        
    }
    return $tabs;
}

function csv_import_admin_header($request)
{
    if ($request->getModuleName() == 'csv-import') {
        queue_css('csv_import_main');
        queue_js('csv-import');
    }
}

/**
 * Get the default value for an element.  If the user has already submitted the 
 * value, then use that as the default, else return null.
 *
 * @param string Element input name
 * @param string Default value of the element  @return string
 */
function csv_import_get_default_value($htmlInputElementName, $defaultValue = null) 
{
    // set the default file if the form is already submitted
    if (isset($_POST[$htmlInputElementName])) {
        $defaultValue = $_POST[$htmlInputElementName];
    }
    return $defaultValue;
}

/**
* Get the html code for mapping columns in the csv file to elements
*  
* @return string
*/
function csv_import_get_column_mappings($csvImportFile, $csvImportItemTypeId) 
{   
    $ht = '';    
    $colNames = $csvImportFile->getColumnNames();
    $colExamples = $csvImportFile->getColumnExamples();
    
    $itemElementIdsToNames = array();
    $ht .= '<table id="csv-import-column-mappings-table" class="simple" '
         . 'cellspacing="0" cellpadding="0">';
	$ht .= '<thead>';
	$ht .= '<tr>';
	$ht .= '<th>Column</th>';
	$ht .= '<th>Example from CSV File</th>';
	$ht .= '<th>Map To Element</th>';
	$ht .= '<th>Use HTML?</th>';
	$ht .= '<th>Tags?</th>';
	$ht .= '<th>File?</th>';
	$ht .= '</tr>';
	$ht .= '</thead>';
	$ht .= '<tbody>';
	
    for($i = 0; $i < count($colNames); $i++) {
        $ht .= '<tr>';
        $ht .= '<td><strong>'.$colNames[$i].'</strong></td>';
        $ht .= '<td>&quot;' . $colExamples[$i] . '&quot;</td>';         
        $ht .= '<td>' . csv_import_get_elements_for_column_mapping($i, 
            $csvImportItemTypeId) . '</td>';
        $ht .= '<td>' 
            . __v()->formCheckbox(CSV_IMPORT_COLUMN_MAP_HTML_CHECKBOX_PREFIX 
            . $i) . '</td>';
        $ht .= '<td>' 
            . __v()->formCheckbox(CSV_IMPORT_COLUMN_MAP_TAG_CHECKBOX_PREFIX 
            . $i) . '</td>';
        $ht .= '<td>' 
            . __v()->formCheckbox(CSV_IMPORT_COLUMN_MAP_FILE_CHECKBOX_PREFIX 
            . $i) . '</td>';
        $ht .= '</tr>';
    }
	$ht .= '</tbody>';
	$ht .= '</table>';
	
    return $ht;
}

/**
 * Gets a div that allows users to add and remove elements for an column mapping
 * 
 * @return string
 */
function csv_import_get_elements_for_column_mapping($columnIndex, $itemTypeId)
{
    $elementsDropDownName = CSV_IMPORT_COLUMN_MAP_ELEMENTS_DROPDOWN_PREFIX 
        . $columnIndex;
    $elementsHiddenInputName 
        = CSV_IMPORT_COLUMN_MAP_ELEMENTS_HIDDEN_INPUT_PREFIX . $columnIndex;
    $elementsListName = CSV_IMPORT_COLUMN_MAP_ELEMENTS_LIST_PREFIX 
        . $columnIndex;
    
    $ht = '';
    $ht .= '<div>';
    $ht .= csv_import_get_item_elements_drop_down($elementsDropDownName, 
        $itemTypeId, $elementsListName, $elementsHiddenInputName);
    $ht .= '<input type="hidden" value="' 
        . csv_import_get_default_value($elementsHiddenInputName) . '" name="' 
        . $elementsHiddenInputName . '" id="' . $elementsHiddenInputName .'" 
        />';
    $ht .= '<span id="' . $elementsListName . '"></span>';
    $ht .= '</div>';
    return $ht;
}

/**
* Get the drop down html code that includes item elements from all of the item 
* element sets, except for the "Item Type Metadata" element set, only get the 
* elements for the item type
*  
* @return string
*/
function csv_import_get_item_elements_drop_down($elementsDropDownName, 
    $itemTypeId, $elementsListName, $elementsHiddenInputName)
{    
    $ht = '';
    
    // get an associative array of elements where the key is the element set 
    // name and the value is the array of elements associated with the element 
    // set order the element sets by: Dublin Core, item type, and then all other element 
    // sets
    $elementsByElementSetName = 
        csv_import_get_elements_by_element_set_name($itemTypeId);
    //$onChange .= "csvImportAddElementToColumnMap('" . $elementsListName . "', 
    //'" . $elementsDropDownName ."', '" . $elementsHiddenInputName 
    //. "');this.selectedIndex=0;";
    
    // get the select dropdown box
    $ht .= select( array('name' => $elementsDropDownName, 'id' => 
        $elementsDropDownName, 'class'=>'csv-import-element-select'), 
        $elementsByElementSetName, 
        csv_import_get_default_value($elementsDropDownName), null);
    
    return $ht;
}

/**
 * @return array
 */
function csv_import_get_elements_by_element_set_name($itemTypeId)
{
    // @todo Fix ElementTable::findPairsForSelectForm() to use passed 
    // parameters.  Also add a search filter to return elements for a specific 
    // item type.
    $elements = get_db()->getTable('Element')->findPairsForSelectForm();
    unset($elements[ELEMENT_SET_ITEM_TYPE]);
    return $elements;
}

function csv_import_config_form()
{  
    if (!$memoryLimit = get_option('csv_import_memory_limit')) {
        $memoryLimit = ini_get('memory_limit');
    }
?>
    <div class="field">
        <label for="csv_import_memory_limit">Memory Limit</label>
        <?php echo __v()->formText('csv_import_memory_limit', $memoryLimit, null);?>
        <p class="explanation">Set a high memory limit to avoid memory 
allocation issues during harvesting. Examples include 128M, 1G, and -1. The 
available options are K (for Kilobytes), M (for Megabytes) and G (for 
Gigabytes). Anything else assumes bytes. Set to -1 for an infinite limit. Be 
advised that many web hosts set a maximum memory limit, so this setting may be 
ignored if it exceeds the maximum allowable limit. Check with your web host for 
more information.</p>
    </div>
<?php
}

function csv_import_config()
{
    set_option('csv_import_memory_limit', $_POST['csv_import_memory_limit']);
}

function csv_error_handler($errno , $errstr, $errfile, $errline, array $errcontext)
{
    die("$errstr ($errfile:$errline)");
}
set_error_handler('csv_error_handler');
