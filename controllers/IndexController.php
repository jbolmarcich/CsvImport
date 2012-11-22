<?php
/**
 * The CvsImport index controller class.
 *
 * @copyright Copyright 2008-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @version $Id$
 * @package CsvImport
 * @author CHNM 
 */
class CsvImport_IndexController extends Omeka_Controller_AbstractActionController
{
    protected $_browseRecordsPerPage = 10;
    private $_pluginConfig = array();

    public function init()
    {
        $this->session = new Zend_Session_Namespace('CsvImport');
        $this->_helper->db->setDefaultModelName('CsvImport_Import');        
    }

    public function indexAction()
    {
        $form = $this->_getMainForm();
        $this->view->form = $form;

        if (!$this->getRequest()->isPost()) {
            return;
        }

        if (!$form->isValid($this->getRequest()->getPost())) {
            $this->_helper->flashMessenger(__('Invalid form input. Please see errors below and try again.'), 'error');
            return;
        }

        if (!$form->csv_file->receive()) {
            $this->_helper->flashMessenger(__('Error uploading file. Please try again.'), 'error');
            return;
        }

        $filePath = $form->csv_file->getFileName();
        $filename = $_FILES['csv_file']['name'];
        $delimiter = $form->getValue('column_delimiter');
        $file = new CsvImport_File($filePath, $delimiter);
        
        if (!$file->parse()) {
            $this->_helper->flashMessenger(__('Your file is incorrectly formatted.') . ' ' . $file->getErrorString(), 'error');
            return;
        }

        $this->session->setExpirationHops(2);
        $this->session->originalFilename = $filename;
        $this->session->filePath = $filePath;
        $this->session->columnDelimiter = $delimiter;
        
        $this->session->itemTypeId = $form->getValue('item_type_id');
        $this->session->itemsArePublic = $form->getValue('items_are_public');
        $this->session->itemsAreFeatured = $form->getValue('items_are_featured');
        $this->session->collectionId = $form->getValue('collection_id');
        $this->session->columnNames = $file->getColumnNames();
        $this->session->columnExamples = $file->getColumnExamples();
        $this->session->ownerId = $this->getInvokeArg('bootstrap')->currentuser->id;

        if ($form->getValue('omeka_csv_export')) {
            $this->_helper->redirector->goto('check-omeka-csv');
        }

        $this->_helper->redirector->goto('map-columns');
    }
    
    public function mapColumnsAction()
    {
        if (!$this->_sessionIsValid()) {
            $this->_helper->flashMessenger(__('Import settings expired. Please try again.'), 'error');
            $this->_helper->redirector->goto('index');
            return;
        }

        require_once CSV_IMPORT_DIRECTORY . '/forms/Mapping.php';
        $form = new CsvImport_Form_Mapping(array(
            'itemTypeId' => $this->session->itemTypeId,
            'columnNames' => $this->session->columnNames,
            'columnExamples' => $this->session->columnExamples,
        ));
        $this->view->form = $form;
                
        if (!$this->getRequest()->isPost()) {
            return;
        }
        if (!$form->isValid($this->getRequest()->getPost())) {
            $this->_helper->flashMessenger(__('Invalid form input. Please try again.'), 'error');
            return;
        }

        $columnMaps = $form->getMappings();
        if (count($columnMaps) == 0) {
            $this->_helper->flashMessenger(__('Please map at least one column to an element, file, or tag.'), 'error');
            return;
        }
        
        $csvImport = new CsvImport_Import();
        foreach ($this->session->getIterator() as $key => $value) {
            $setMethod = 'set' . ucwords($key);
            if (method_exists($csvImport, $setMethod)) {
                $csvImport->$setMethod($value);
            }
        }
        $csvImport->setColumnMaps($columnMaps);
        $csvImport->setStatus(CsvImport_Import::QUEUED);
        $csvImport->save();

        $csvConfig = $this->_getPluginConfig();
        $jobDispatcher = Zend_Registry::get('job_dispatcher');
        $jobDispatcher->setQueueName(CsvImport_ImportTask::QUEUE_NAME);
        $jobDispatcher->sendLongRunning('CsvImport_ImportTask',
            array(
                'importId' => $csvImport->id,
                'memoryLimit' => @$csvConfig['memoryLimit'],
                'batchSize' => @$csvConfig['batchSize'],
            )
        );

        $this->session->unsetAll();
        $this->_helper->flashMessenger(__('Import started. Reload this page for status updates.'), 'success');
        $this->_helper->redirector->goto('browse');
    }
    
    /**
     * For import of Omeka.net CSV. Checks if the user didn't read the manual and so didn't make sure all needed Elements are present
     */

    public function checkOmekaCsvAction()
    {
        $elementTable = get_db()->getTable('Element');
        $skipColumns = array('itemType', 
                             'collection',
                             'tags',
                             'public',
                             'featured',
                             'file');
        $errors = array();
        foreach ($this->session->columnNames as $columnName){
            if (!in_array($columnName, $skipColumns)) {
                $data = explode(':', $columnName);
                //$data is like array('Element Set Name', 'Element Name');
                //dig up the element_id
                $element = $elementTable->findByElementSetNameAndElementName($data[0], $data[1]);
                if(empty($element)) {                    
                    $errors[] = array('set'=>$data[0], 'element'=>$data[1]);
                }                                
            }            
        }
        
        if (empty($errors)) {
            $this->_helper->redirector->goto('omeka-csv');
        } else {
            $this->view->errors = $errors;
        }
    }
    
    public function omekaCsvAction()
    {
        $headings = $this->session->columnNames;
        $columnMaps = array();
        foreach ($headings as $heading) {
            switch ($heading) {
                case 'collection':
                    $columnMaps[] = new CsvImport_ColumnMap_Collection($heading);
                    break;
                case 'itemType':
                    $columnMaps[] = new CsvImport_ColumnMap_ItemType($heading);
                    break;
                case 'file':
                    $columnMaps[] = new CsvImport_ColumnMap_File($heading);
                    break;
                case 'tags':
                    $columnMaps[] = new CsvImport_ColumnMap_Tag($heading);
                    break;
                case 'public':
                    $columnMaps[] = new CsvImport_ColumnMap_Public($heading);
                    break;
                case 'featured':
                    $columnMaps[] = new CsvImport_ColumnMap_Featured($heading);
                    break;
                default:
                    //@TODO: make sure this doesn't break regular import
                    $columnMaps[] = new CsvImport_ColumnMap_ExportedElement($heading);
                    break;
            }
        }
        $csvImport = new CsvImport_Import();
        
        //this is the clever way that mapColumns action sets the values passed along from indexAction
        //many will be irrelevant here, since CsvImport allows variable itemTypes and Collection
        
        //@TODO: check if variable itemTypes and Collections breaks undo. It probably should, actually
        foreach ($this->session->getIterator() as $key => $value) {
            $setMethod = 'set' . ucwords($key);
            if (method_exists($csvImport, $setMethod)) {
                $csvImport->$setMethod($value);
            }
        }
        $csvImport->setColumnMaps($columnMaps);
        $csvImport->setStatus(CsvImport_Import::QUEUED);
        $csvImport->save();

        $csvConfig = $this->_getPluginConfig();
        $jobDispatcher = Zend_Registry::get('job_dispatcher');
        $jobDispatcher->setQueueName(CsvImport_ImportTask::QUEUE_NAME);
        $jobDispatcher->sendLongRunning('CsvImport_ImportTask',
                                        array('importId' => $csvImport->id,
                                               'memoryLimit' => @$csvConfig['memoryLimit'],
                                               'batchSize' => @$csvConfig['batchSize'],
            )
        );

        $this->session->unsetAll();
        $this->_helper->flashMessenger(__('Import started. Reload this page for status updates.'), 'success');
        $this->_helper->redirector->goto('browse');
    }
    
    public function undoImportAction()
    {
        $csvImport = $this->_helper->db->findById();
        $csvImport->status = CsvImport_Import::QUEUED;
        $csvImport->save();

        $jobDispatcher = Zend_Registry::get('job_dispatcher');
        $jobDispatcher->setQueueName(CsvImport_ImportTask::QUEUE_NAME);
        $jobDispatcher->sendLongRunning('CsvImport_ImportTask',
                                        array('importId' => $csvImport->id, 
                                               'method' => 'undo'));
        $this->_helper->flashMessenger(__('Undo import started. Reload this page for status updates.'), 'success');
        $this->_helper->redirector->goto('browse');
    }
    
    public function clearHistoryAction()
    {
        $csvImport = $this->_helper->db->findById();
        if ($csvImport->status ==
            CsvImport_Import::COMPLETED_UNDO
        ) {
            $csvImport->delete();
            $this->_helper->flashMessenger(__('Cleared import from the history.'), 'success');
        }
        $this->_helper->redirector->goto('browse');
    }
    
    private function _getMainForm()
    {
        require_once CSV_IMPORT_DIRECTORY . '/forms/Main.php';
        $csvConfig = $this->_getPluginConfig();
        $form = new CsvImport_Form_Main($csvConfig);
        return $form;
    }

    private function _getPluginConfig()
    {
        if (!$this->_pluginConfig) {
            $config = $this->getInvokeArg('bootstrap')->config->plugins;
            if ($config && isset($config->CsvImport)) {
                $this->_pluginConfig = $config->CsvImport->toArray();
            }
            if (!array_key_exists('fileDestination', $this->_pluginConfig)) {
                $this->_pluginConfig['fileDestination'] =
                    Zend_Registry::get('storage')->getTempDir();
            }
        }
        return $this->_pluginConfig;
    }
    
    private function _sessionIsValid()
    {
        $requiredKeys = array('itemsArePublic', 
                              'itemsAreFeatured',
                              'collectionId', 
                              'itemTypeId', 
                              'ownerId');
        foreach ($requiredKeys as $key) {
            if (!isset($this->session->$key)) {
                return false;
            }
        }
        return true;
    }
}
