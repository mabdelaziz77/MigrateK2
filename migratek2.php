<?php
/**
 * @package    Joomla.Cli
 *
 * @copyright  (C) Mohamed Abdelaziz . <https://www.joomreem.com>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

 use Joomla\Utilities\ArrayHelper;
 use Joomla\Registry\Registry;

// Set flag that this is a parent file.
const _JEXEC = 1;

error_reporting(E_ERROR);
ini_set('display_errors', 0);

// Load system defines
if (file_exists(dirname(__DIR__) . '/defines.php'))
{
	require_once dirname(__DIR__) . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', dirname(__DIR__));
	require_once JPATH_BASE . '/includes/defines.php';
}

require_once JPATH_LIBRARIES . '/import.legacy.php';
require_once JPATH_LIBRARIES . '/cms.php';

// Load the configuration
require_once JPATH_CONFIGURATION . '/configuration.php';

class Migratek2 extends JApplicationCli
{
	/**
	 * Entry point for the script
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */

    public $K2ExtraFields = array();
    public $cfMapping = array();

	public function doExecute()
	{

		$options['format']    = '{DATE}\t{TIME}\t{LEVEL}\t{CODE}\t{MESSAGE}';
		$options['text_file'] = 'migrate_k2.log.php';

		JLog::addLogger($options);

		$credentials = array(
			'username'  => '',
			'password'  => '',
			'secretkey' => '',
		);

        $this->loadConfiguration($this->fetchConfigurationData(JPATH_BASE . '/cli/migratek2/config.php', $class = 'MigK2Config'));
        $this->out("Enter secret key if 2FA is enabled, otherwise leave blank:", false);
        $credentials['secretkey'] = $this->in();
		$credentials['username'] = $this->config->get("migk2Username");
        $credentials['password'] = $this->config->get("migk2Password");
		
        $app = JFactory::getApplication('site');
        try {
            $result = $app->login($credentials, array('action' => 'core.login.admin'));    
            if ($result) {
                $this->out('Super admin login successful!');
            } else {
                $this->out('Login information is incorrect! Try again...');
                $this->close();
            }
        } catch (Exception $e) {
            $this->out('Super admin login failed! ' . $e->getMessage());
            $this->close();
        }
		
        JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models');
        JLoader::register('FieldsHelper', JPATH_ADMINISTRATOR . '/components/com_fields/helpers/fields.php');

        $this->log('Start migrating k2 database...', JLog::INFO);
        
        // Find all K2 categories
        $mappingCatid = array();
        $k2categories = $this->getK2CategoriesTree();
        $db = JFactory::getDbo();
        $k2ItemsPerLoop = (int) $this->config->get("itemsPerLoop");
        $this->cfMapping = (array)$this->config->get("cfMapping");
        $this->K2ExtraFields = $this->_getK2ExtraFields();
        // Keep running the script until it's finished or the time limit is reached
        foreach ($k2categories as $k2category){
            $this->out('Starting to migrate K2 category: ' . $k2category->name);
            $db->setQuery("SELECT id FROM #__categories WHERE title ='". $db->qn($db->escape($k2category->name))."'");
            $categoryId = $db->loadResult();
            if($categoryId > 0) {
                $mappingCatid[$k2category->id] = $categoryId;
            } else {
                $category =JTable::getInstance('Category', 'JTable');
                if ($k2category->image && JFile::exists(realpath(JPATH_SITE.'/media/k2/categories/'.$k2category->image))) {
                    JFile::copy(realpath(JPATH_SITE.'/media/k2/categories/'.$k2category->image), JPATH_SITE.'/images/'.$k2category->image);
                    $category->params->image =  'images/'.$k2category->image;
                }
                else {
                    $category->params->image = '';
                }
                $category->params = json_encode($category->params);
                $category->title = $k2category->name;
                $category->extension = 'com_content';
                $category->description = $k2category->description;
                if(array_key_exists($k2category->parent, $mappingCatid)){
                    $category->parent_id = $mappingCatid[$k2category->parent];
                } else {
                    $category->parent_id = $k2category->parent;
                }
                if($category->parent_id == 0){
                    $category->parent_id = 1;
                }
                $category->setLocation($category->parent_id, 'last-child');
                $category->published = $k2category->published;
                if ($k2category->trash == 1){
                    $category->published = -2;
                }
                $category->access = $k2category->access;
                $category->language = $k2category->language;
                if (!$category->check())
                {
                    $this->log('K2 Category ID: ' . $k2category->id . ', '.$category->getError(), JLog::ERROR);
                    continue;
                }
                if(!$category->store()){
                    $this->log('K2 Category ID: ' . $k2category->id . ', '.$category->getError(), JLog::ERROR);
                    continue;
                }

                $mappingCatid[$k2category->id] = $category->id;
                if (!$category->rebuildPath($category->id))
                {
                    $this->log('K2 Category ID: ' . $k2category->id . ', '.$category->getError(), JLog::ERROR);
                    continue;
                }
                if (!$category->rebuild($category->id, $category->lft, $category->level, $category->path))
                    // Rebuild the paths of the category's children:
                {
                    $this->log('K2 Category ID: ' . $k2category->id . ', '.$category->getError(), JLog::ERROR);
                    continue;
                }
            } 
            
            $query = "SELECT k2article.*
                FROM #__k2_items AS k2article
                WHERE catid = ".(int)$k2category->id;
            $db->setQuery($query);
            $k2Items = $db->loadObjectList();
            $itemsFeatured = array();
            $itemsMigrated = 0;
            foreach ($k2Items as $k2Item){
                $itemsMigrated++;
                if($itemsMigrated % $k2ItemsPerLoop == 0) sleep(1);
                $this->out('Starting to migrate K2 Item: ' . $k2Item->title);
                $db->setQuery("SELECT id FROM #__content WHERE title ='". $db->qn($db->escape($k2Item->title))."'");
                $articleId = $db->loadResult();
                if($articleId > 0) continue;
                $item = JTable::getInstance('Content', 'JTable');
                $item->title = $k2Item->title;
                $item->alias = $k2Item->title;
                $item->catid = $mappingCatid[$k2category->id];

                $item->state = $k2Item->published;
                if ($k2Item->trash == 1){
                    $item->state = -2;
                }
                $item->featured = $k2Item->featured;
                $item->introtext = $k2Item->introtext;
                $item->fulltext = $k2Item->fulltext;
                $item->created = $k2Item->created;
                $item->created_by = $k2Item->created_by;
                $item->created_by_alias = $k2Item->created_by_alias;
                $item->modified = $k2Item->modified;
                $item->modified_by = $k2Item->modified_by;
                $item->publish_up = $k2Item->publish_up;
                $item->publish_down = $k2Item->publish_down;
                $item->access = $k2Item->access;
                $item->ordering = $k2Item->ordering;
                $item->hits = $k2Item->hits;
                $item->metadesc = $k2Item->metadesc;
                $item->metadata = $k2Item->metadata;
                $item->metakey = $k2Item->metakey;
                $itemParams = $k2Item->params;
                $catParams = $k2category->params;
                $this->copyParams($itemParams,$catParams,$item);
                $item->newTags = $this->getTags($k2Item->id);
                $imgFilename = md5("Image". $k2Item->id) . '.jpg';
                if (JFile::exists(realpath(JPATH_SITE.'/media/k2/items/src/'.$imgFilename))) {
                    JFile::copy(realpath(JPATH_SITE.'/media/k2/items/src/'.$imgFilename), JPATH_SITE.'/images/'.$imgFilename);
                    $images= json_decode('{"image_intro":"images\\/'. $imgFilename .'",
                            "float_intro":"",
                            "image_intro_alt":"",
                            "image_intro_caption":"",
                            "image_fulltext":"images\\/'. $imgFilename .'",
                            "float_fulltext":"",
                            "image_fulltext_alt":"",
                            "image_fulltext_caption":""
                            }',
                    true);
                    $registry = new Registry($images);
                    $item->images = (string) $registry;
                }
                
                $item->language = $k2Item->language;
                $item->check();
                if(!$item->store()){
                    $this->log('K2 Item ID: ' . $k2Item->id . ', '. $item->getError(), JLog::ERROR);
                    continue;
                }

                $extraFields = json_decode($k2Item->extra_fields);

                if (count($extraFields) > 0) {
                    $this->_copyCustomFields($item, $extraFields);    
                }
                
                $attachments = $this->getAttachments($k2Item->id);
                if (count($attachments) > 0) {
                    $this->_addAttachments($item, $attachments);
                }
                if($k2Item->featured == 1){
                	$itemsFeatured[] = $item->id;
                }					
            }
            if(count($itemsFeatured) > 0)  $this->saveFeatured($itemsFeatured);
        }

        $this->log('Finished migrating K2 database', JLog::INFO);
	}


	function  getK2CategoriesTree($row = null){
        $db = JFactory::getDbo();
        if (isset($row->id)) {
            $idCheck = ' AND id != '.(int) $row->id;
        } else {
            $idCheck = null;
        }
        if (!isset($row->parent)) {
            if (is_null($row)) {
                $row = new stdClass;
            }
            $row->parent = 0;
        }
        $query = "SELECT m.* FROM #__k2_categories m WHERE id > 0 {$idCheck}";

        $query .= " ORDER BY parent, ordering";
        $db->setQuery($query);
        $mitems = $db->loadObjectList();
        return $mitems;
    }

	function saveFeatured($itemsFeatured){

        $itemsFeatured = (array) $itemsFeatured;
        $itemsFeatured = ArrayHelper::toInteger($itemsFeatured);
        $db = JFactory::getDbo();

        // Featuring.
        $tuples = array();

        foreach ($itemsFeatured as $pk)
        {
            $tuples[] = $pk . ', 0';
        }

        if (count($tuples))
        {
            $columns = array('content_id', 'ordering');
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__content_frontpage'))
                ->columns($db->quoteName($columns))
                ->values($tuples);
            $db->setQuery($query);
            $db->execute();
        }
    }

    function copyParams($itemParams, $catParams ,&$article ){
        $itemregistry = new Registry($itemParams);
        $catRegistry = new Registry($catParams);
        $attribs = new Registry($article->attribs);

        $attribs->set('show_title', $itemregistry->get('itemTitle', $catRegistry->get('itemTitle') ));
        $attribs->set('show_tags', $itemregistry->get('itemTags', $catRegistry->get('itemTags') ));
        $attribs->set('show_intro', $itemregistry->get('itemIntroText', $catRegistry->get('itemIntroText') ));
        $attribs->set('show_category', $itemregistry->get('itemCategory', $catRegistry->get('itemCategory') ));
        $attribs->set('show_author', $itemregistry->get('itemAuthor', $catRegistry->get('itemAuthor') ));
        $attribs->set('link_author', $itemregistry->get('itemAuthorURL', $catRegistry->get('itemAuthorURL') ));
        $attribs->set('show_create_date', $itemregistry->get('itemDateCreated', $catRegistry->get('itemDateCreated') ));
        $attribs->set('show_modify_date', $itemregistry->get('itemDateModified', $catRegistry->get('itemDateModified') ));
        $attribs->set('show_item_navigation', $itemregistry->get('itemNavigation', $catRegistry->get('itemNavigation') ));
        $attribs->set('show_print_icon', $itemregistry->get('itemPrintButton', $catRegistry->get('itemPrintButton') ));
        $attribs->set('show_email_icon', $itemregistry->get('itemEmailButton', $catRegistry->get('itemEmailButton') ));
        $attribs->set('show_vote', $itemregistry->get('itemRating', $catRegistry->get('itemRating') ));
        $attribs->set('show_hits', $itemregistry->get('itemHits', $catRegistry->get('itemHits') ));

        $article->attribs = $attribs->toString();

    }

    public function getTags($itemID)
    {
        $itemID = (int)$itemID;        
        $db = JFactory::getDbo();
        $query = "SELECT tag.name
            FROM #__k2_tags AS tag
            JOIN #__k2_tags_xref AS xref ON tag.id = xref.tagID
            WHERE tag.published = 1
                AND xref.itemID = ".(int)$itemID."
            ORDER BY xref.id ASC";

        $db->setQuery($query);
        $rows = $db->loadObjectList();     
        $contentTags = array();
        for($i =0; $i < count($rows); $i++){
            $db->setQuery("SELECT id FROM #__tags WHERE title = '". $db->qn($db->escape($rows[$i]->name))."'");
            $id = $db->loadResult();
            if($id > 0) {
                $contentTags[] = "{$id}";
            } else {
                $contentTags[]="#new#{$rows[$i]->name}";
            }
        }   
        return $contentTags;
    }

    private function _getK2ExtraFields() {
        $db = JFactory::getDbo();
        $query = "SELECT * FROM #__k2_extra_fields";
        $db->setQuery($query);
        $rows = $db->loadObjectList('id');
        // $efByAlias = array();
        foreach ($rows as &$row) {
            $row->value = json_decode($row->value, true);
            $row->field_info = ArrayHelper::pivot($row->value, 'value');
            // $efByAlias[$row->alias] = $row;
        }
        return $rows;

    }

    function getAttachments($itemID)
	{
		$db = JFactory::getDbo();
		$query = "SELECT * FROM #__k2_attachments WHERE itemID=".(int)$itemID;
		$db->setQuery($query);
		$rows = $db->loadObjectList();
		
		return $rows;
	}

    /**
     * Adds attachments to an item.
     * @param object $item The Joomla content item to copy custom fields to.
     * @param array $attachments A list of attachments to be added.
     * @return void
     */
    private function _addAttachments($item, $attachments){
        $attachmentsFieldId = (int) $this->config->get('attachmentCFId');
        $attachmentsFolder = $this->config->get('attachmentsFolder');
        if($attachmentsFieldId > 0){
            $newAttachments = array();
            foreach ($attachments as $attachment) {
                if (JFile::exists(realpath(JPATH_SITE.'/media/k2/attachments/'.$attachment->filename))) {
                    JFile::copy(realpath(JPATH_SITE.'/media/k2/attachments/'.$attachment->filename), JPATH_SITE.'/'.$attachmentsFolder.'/'.$attachment->filename);
                    $newAttachments[] = array(
                        'title' => $attachment->title,
                        'description' => $attachment->title,
                        'value' => $attachmentsFolder.'/'. $attachment->filename,
                    );
                }
            }
            if(count($newAttachments) > 0){
                $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', ['ignore_request' => true]);
                
                $fieldModel->setFieldValue(
                    $attachmentsFieldId,
                    $item->id,
                    json_encode($newAttachments)
                );
            }
        }
    }


    /**
     * Copies custom fields from a K2 item to a Joomla content item.
     *
     * @param object $item The Joomla content item to copy custom fields to.
     * @param object $k2Item The K2 item to copy custom fields from.
     * @return void
     */
    private function _copyCustomFields($item, $extraFields){
        $fieldModel = JModelLegacy::getInstance('Field', 'FieldsModel', ['ignore_request' => true]);                  
        foreach ($extraFields as $eField) {
            $value = null;
            switch ($this->K2ExtraFields[$eField->id]->type) {
                case 'link':
                    $value = $eField->value[1];
                    break;
                case 'multipleSelect':
                    $efOptions = $this->K2ExtraFields[$eField->id]->field_info;
                    $value = array();
                    foreach ($eField->value as $efValue) {
                        // In case MEFGforK2 is used to support options as icons/images
                        $selected = explode('|', $efOptions[$efValue]['name']);
                        $value[] = $selected[0];                  
                    }
                    break;
                default:
                    $value = $eField->value;
                    break;  
            }
            $fieldModel->setFieldValue(
                (int)$this->cfMapping[$eField->id],
                $item->id,
                $value
            );
        }
    }
    /**
     * Logs a message with a specified level.
     *
     * @param string $message The message to be logged.
     * @param string $level   The level of the message (e.g. INFO, ERROR, etc.).
     *
     * @return void
     */
    public function log($message, $level){
        $this->out($message);
        JLog::add($message, $level);
    }
}

JApplicationCli::getInstance('Migratek2')->execute();
