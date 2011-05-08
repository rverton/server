<?php
/**
 * Class for the handling Bulk upload using XML in the system 
 * 
 * @package Scheduler
 * @subpackage Provision
 */
class BulkUploadEngineXml extends KBulkUploadEngine
{
	/**
	 * 
	 * The add action (default) string
	 * @var string
	 */
	const ADD_ACTION_STRING = "add";

	/**
	 * 
	 * The defalut thumbnail tag
	 * @var string
	 */
	const DEFAULT_THUMB_TAG = 'default_thumb';
	
	/**
	 * 
	 * The default ingestion profile id
	 * @var int
	 */
	private $defaultIngestionProfileId = null;
	
	/**
	 * 
	 * Holds the number of the current proccessed item
	 * @var int
	 */
	private $currentItem = 0;
	
	/**
	 * 
	 * The engine xsd file path
	 * @var string
	 */
	private $xsdFilePath = "/../xml/ingestion.xsd";

	/**
	 * 
	 * Maps the flavor params name to id
	 * @var array()
	 */
	private $assetParamsNameToIdPerConversionProfile = null;

	/**
	 * Maps the asset id to flavor params id
	 * @var array()
	 */
	private $assetIdToAssetParamsId = null;
	
	/**
	 * 
	 * Maps the access control name to id
	 * @var array()
	 */
	private $accessControlNameToId = null;
	
	/**
	 * 
	 * Maps the converstion profile name to id
	 * @var array()
	 */
	private $ingestionProfileNameToId = array();
	
	/**
	 * 
	 * Maps the storage profile name to id
	 * @var array()
	 */
	private $storageProfileNameToId = null;

	/**
	 * @param KSchedularTaskConfig $taskConfig
	 */
	public function __construct( KSchedularTaskConfig $taskConfig, KalturaClient $kClient, KalturaBatchJob $job)
	{
		parent::__construct($taskConfig, $kClient, $job);
		
		if($taskConfig->params->xsdFilePath) {
			$this->xsdFilePath = dirname(__FILE__).$taskConfig->params->xsdFilePath;
		}
		else {
			$this->xsdFilePath = dirname(__FILE__).$this->xsdFilePath;
		}
	}
	
	/* (non-PHPdoc)
	 * @see KBulkUploadEngine::HandleBulkUpload()
	 */
	public function handleBulkUpload() 
	{
		$this->validate();
	    $this->parse();
	}
	
	/**
	 * 
	 * Validates that the xml is valid using the XSD
	 *@return bool - if the validation is ok
	 */
	protected function validate() 
	{
		libxml_use_internal_errors(true);
		libxml_clear_errors();
						
		$xdoc = new DomDocument;
		$xdoc->Load($this->data->filePath);
		//Validate the XML file against the schema
		if(!$xdoc->schemaValidate(realpath($this->xsdFilePath))) 
		{
			$errorMessage = kXml::getLibXmlErrorDescription(file_get_contents($this->data->filePath));
			KalturaLog::debug("XML is invalid:\n$errorMessage");
			throw new KalturaBatchException("Validate files failed on job [{$this->job->id}], $errorMessage", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
		}
		
		return true;
	}

	/**
	 * 
	 * Parses the Xml file lines and creates the right actions in the system
	 */
	protected function parse() 
	{
		$xdoc = simplexml_load_file($this->data->filePath);
		
		foreach( $xdoc->channel as $channel)
		{
			KalturaLog::debug("Handling channel");
			$this->handleChannel($channel);
			if($this->exceededMaxRecordsEachRun) // exit if we have proccessed max num of items
				return;
		}
	}

	/**
	 * 
	 * Gets and handles a channel from the mrss
	 * @param SimpleXMLElement $channel
	 */
	protected function handleChannel(SimpleXMLElement $channel)
	{
		$this->currentItem = 0;
		$startIndex = $this->getStartIndex();
		KalturaLog::debug("startIndex [$startIndex] ");		
		
		//Gets all items from the channel
		foreach( $channel->item as $item)
		{
			if($this->currentItem < $startIndex)
			{
				$this->currentItem++;
				continue;
			}
			
			if($this->exceededMaxRecordsEachRun) // exit if we have proccessed max num of items
				return;
			
			$this->currentItem++; //move to the next item (first item is 1)
			try
			{
				KalturaLog::debug("Validating item [{$item->name}]");
				$this->validateItem($item);
				
				KalturaLog::debug("Handling item [{$item->name}]");
				$this->handleItem($item);
			}
			catch (KalturaBulkUploadXmlException $e)
			{
				KalturaLog::err("Item failed because excpetion was raised': " . $e->getMessage());
				$bulkUploadResult = $this->createUploadResult($item);
				$bulkUploadResult->errorDescription = $e->getMessage();
				$bulkUploadResult->entryStatus = KalturaEntryStatus::ERROR_IMPORTING;
				$this->addBulkUploadResult($bulkUploadResult);
			}			
		}	
	}
	
	/**
	 * 
	 * Validates the given item so it's valid (some validation can't be enforced in the schema)
	 * @param SimpleXMLElement $item
	 */
	protected function validateItem(SimpleXMLElement $item)
	{
		//Validates that the item type has a matching type element
		$this->validateTypeToTypedElement($item);
	}		
	
	/**
	 * 
	 * Gets and handles an item from the channel
	 * @param SimpleXMLElement $item
	 */
	protected function handleItem(SimpleXMLElement $item)
	{
		$this->checkAborted();
		
		$actionToPerform = self::ADD_ACTION_STRING;
				
		if(isset($item->action))
			$actionToPerform = strtolower($item->action);
		
		switch($actionToPerform)
		{
			case "add":
				$this->handleItemAdd($item);
				break;
			case "update":
				$this->handleItemUpdate($item);
				break;
			case "delete":
				$this->handleItemDelete($item);
				break;
			default :
				throw new KalturaBatchException("Action: {$actionToPerform} is not supported", KalturaBatchJobAppErrors::BULK_ACTION_NOT_SUPPORTED);
		}
	}

	/**
	 * 
	 * Gets the flavor params from the given flavor asset
	 * @param string $assetId
	 */
	protected function getAssetParamsIdFromAssetId($assetId, $entryId)
	{
		if(is_null($this->assetIdToAssetParamsId[$entryId]))
		{
			$this->initassetIdToAssetParamsId($entryId);
		}
		
		if(isset($this->assetIdToAssetParamsId[$entryId][$assetId]))
		{
			return $this->assetIdToAssetParamsId[$entryId][$assetId];
		}
		else //The asset wasn't found on the entry
		{
			throw new KalturaBatchException("Asset Id [$assetId] not found on entry [$entryId]", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
		}
	}
	
	
	/**
	 * 
	 * Handles xml bulk upload update
	 * @param SimpleXMLElement $item
	 * @throws KalturaException
	 */
	protected function handleItemUpdate(SimpleXMLElement $item)
	{
		KalturaLog::debug("xml [" . $item->asXML() . "]");
				
		$entryId = "$item->entryId";
		if(!$entryId)
			throw new KalturaBatchException("Missing entry id element", KalturaBatchJobAppErrors::BULK_MISSING_MANDATORY_PARAMETER);

		$entry = $this->createEntryFromItem($item); //Creates the entry from the item element
		$this->handleTypedElement($entry, $item); //Sets the typed element values (Mix, Media, ...)
		KalturaLog::debug("current entry is: " . print_r($entry, true));
				
		$thumbAssets = array();
		$flavorAssets = array();
		$noParamsThumbAssets = array(); //Holds the no flavor params thumb assests
		$noParamsThumbResources = array(); //Holds the no flavor params resources assests
		$noParamsFlavorAssets = array();  //Holds the no flavor params flavor assests
		$noParamsFlavorResources = array(); //Holds the no flavor params flavor resources
		$resource = new KalturaAssetsParamsResourceContainers(); // holds all teh needed resources for the conversion
		$resource->resources = array();
			
		//For each content in the item element we add a new flavor asset
		foreach ($item->content as $contentElement)
		{
			KalturaLog::debug("contentElement [" . print_r($contentElement->asXml(), true). "]");
			
			if(empty($contentElement)) // if the content is empty skip
			{
				continue;
			}
			
			$assetId = kXml::getXmlAttributeAsString($contentElement, "assetId");
			KalturaLog::debug("Asset id [ $assetId]");
			
			$flavorAsset = $this->getFlavorAsset($contentElement, $entry->ingestionProfileId);
			$flavorAssetResource = $this->getResource($contentElement);
			
			$assetParamsId = $flavorAsset->flavorParamsId;
			 
			if($assetId) // if we have an asset id then we need to update the asset
			{
				$assetParamsId = $this->getAssetParamsIdFromAssetId($assetId, $entryId);
			}
		
			KalturaLog::debug("assetParamsId [$assetParamsId]");
						
			if(is_null($assetParamsId)) // no params resource
			{
				$noParamsFlavorAssets[] = $flavorAsset;
				$noParamsFlavorResources[] = $flavorAssetResource;
				continue;
			}
			
			$flavorAssets[$flavorAsset->flavorParamsId] = $flavorAsset;
			$assetResource = new KalturaAssetParamsResourceContainer();
			$assetResource->resource = $flavorAssetResource;
			$assetResource->assetParamsId = $assetParamsId;
			$resource->resources[] = $assetResource;
		}

		//For each thumbnail in the item element we create a new thumb asset
		foreach ($item->thumbnail as $thumbElement)
		{
			if(empty($thumbElement)) // if the content is empty
			{
				continue;
			}
			
			KalturaLog::debug("thumbElement [" . print_r($thumbElement->asXml(), true). "]");
						
			$thumbAsset = $this->getThumbAsset($thumbElement, $entry->ingestionProfileId);
			$thumbAssetResource = $this->getResource($thumbElement);
			
			$assetId = kXml::getXmlAttributeAsString($thumbElement, "assetId");
			//TODO: get the flavor params from the asset if exists.
			
			KalturaLog::debug("Asset id [ $assetId]");
			
			$assetParamsId = $thumbAsset->thumbParamsId;
			 
			if($assetId) // if we have an asset id then we need to update the asset
			{
				$assetParamsId = $this->getAssetParamsIdFromAssetId($assetId, $entryId);
			}
						
			KalturaLog::debug("assetParamsId [$assetParamsId]");
			
			if(is_null($assetParamsId))
			{
				$noParamsThumbAssets[] = $thumbAsset;
				$noParamsThumbResources[] = $thumbAssetResource;
				continue;
			}
			
			$thumbAssets[$thumbAsset->thumbParamsId] = $thumbAsset;
			$assetResource = new KalturaAssetParamsResourceContainer();
			$assetResource->resource = $thumbAssetResource;
			$assetResource->assetParamsId = $assetParamsId;
			$resource->resources[] = $assetResource;
		}

		$updatedEntry = $this->sendItemUpdateData($entryId, $entry, $resource, 
												  $noParamsFlavorAssets, $noParamsFlavorResources, 
												  $noParamsThumbAssets, $noParamsThumbResources);
					
		//Throw exception in case of  max proccessed items and handle all exceptions there
		$createdEntryBulkUploadResult = $this->createUploadResult($item); 
				
		if($this->exceededMaxRecordsEachRun)
			return;
		
		//Updates the bulk upload result for the given entry (with the status and other data)
		$this->updateEntriesResults(array($updatedEntry), array($createdEntryBulkUploadResult));
		
		//Adds the additional data for the flavors and thumbs
		$this->handleFlavorAndThumbsAdditionalData($updatedEntry->id, $flavorAssets, $thumbAssets);
				
		//Handles the plugin added data
		$pluginsInstances = KalturaPluginManager::getPluginInstances('IKalturaBulkUploadXmlHandler');
		foreach($pluginsInstances as $pluginsInstance)
			$pluginsInstance->handleItemUpdated($this->kClient, $updatedEntry, $item);
	}

	/**
	 * 
	 * Sends the data using a multi requsest according to the given data
	 * @param int $entryID
	 * @param KalturaBaseEntry $entry
	 * @param KalturaAssetsParamsResourceContainers $resource - the main resource collection for the entry
	 * @param array $noParamsFlavorAssets - Holds the no flavor params flavor assets 
	 * @param array $noParamsFlavorResources - Holds the no flavor params flavor resources
	 * @param array $noParamsThumbAssets - Holds the no flavor params thumb assets
	 * @param array $noParamsThumbResources - Holds the no flavor params thumb resources
	 * @return $requestResults - the multi request result
	 */
	protected function sendItemUpdateData($entryId, KalturaBaseEntry $entry ,KalturaAssetsParamsResourceContainers $resource, 
										array $noParamsFlavorAssets, array $noParamsFlavorResources, 
										array $noParamsThumbAssets, array $noParamsThumbResources)
	{
		$this->startMultiRequest(true);
		
		KalturaLog::debug("Resource is: " . print_r($resource, true));
		
		if(!count($resource->resources))
			$resource = null;
			
			//TODO: handle replacment entry issues
		$this->kClient->baseEntry->update($entryId, $entry, $resource); // updates the entry
		$updatedEntryId = "{1:result:id}";
		
		foreach($noParamsFlavorAssets as $index => $flavorAsset) // Adds all the entry flavors
		{
			$flavorResource = $noParamsFlavorResources[$index];
			$this->kClient->flavorAsset->add($updatedEntryId, $flavorAsset, $flavorResource);
		}

		foreach($noParamsThumbAssets as $index => $thumbAsset) //Adds the entry thumb assests
		{
			$thumbResource = $noParamsThumbResources[$index];
			$this->kClient->thumbAsset->add($updatedEntryId, $thumbAsset, $thumbResource);
		}

		$requestResults = $this->kClient->doMultiRequest();;
		
		$updatedEntry = reset($requestResults);
		
		//TODO: handle the update array
		KalturaLog::debug("Updated entry [". print_r($updatedEntry,true) ."]");
		
		//Make thi closer to request
		if(is_null($updatedEntry)) //checks that the entry was created
		{
			throw new KalturaBulkUploadXmlException("The entry wasn't created requestResults [$requestResults]", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
		}
		
		return $updatedEntry;
	}

	/**
	 * 
	 * Handles xml bulk upload delete
	 * @param SimpleXMLElement $item
	 * @throws KalturaException
	 */
	protected function handleItemDelete(SimpleXMLElement $item)
	{
		$entryId = "$item->entryId";
		if(!$entryId)
			throw new KalturaBatchException("Missing entry id element", KalturaBatchJobAppErrors::BULK_MISSING_MANDATORY_PARAMETER);
		
		$result = $this->kClient->baseEntry->delete($entryId);
		
		//TODO: should we check the result?
		return;
	}

	/**
	 * 
	 * Gets an item and insert it into the system
	 * @param SimpleXMLElement $item
	 */
	protected function handleItemAdd(SimpleXMLElement $item)
	{
		KalturaLog::debug("xml [" . $item->asXML() . "]");
			
		$entry = $this->createEntryFromItem($item); //Creates the entry from the item element
		$this->handleTypedElement($entry, $item); //Sets the typed element values (Mix, Media, ...)
		KalturaLog::debug("current entry is: " . print_r($entry, true));
				
		$thumbAssets = array();
		$flavorAssets = array();
		$noParamsThumbAssets = array(); //Holds the no flavor params thumb assests
		$noParamsThumbResources = array(); //Holds the no flavor params resources assests
		$noParamsFlavorAssets = array();  //Holds the no flavor params flavor assests
		$noParamsFlavorResources = array(); //Holds the no flavor params flavor resources
		$resource = new KalturaAssetsParamsResourceContainers(); // holds all teh needed resources for the conversion
		$resource->resources = array();
		
		//For each content in the item element we add a new flavor asset
		foreach ($item->content as $contentElement)
		{
			$assetResource = $this->getResource($contentElement);
			$assetResourceContainer = new KalturaAssetParamsResourceContainer();
			$flavorAsset = $this->getFlavorAsset($contentElement, $entry->ingestionProfileId);
			
			if(is_null($flavorAsset->flavorParamsId))
			{
				KalturaLog::debug("flavorAsset [". print_r($flavorAsset, true) . "]");
				$noParamsFlavorAssets[] = $flavorAsset;
				$noParamsFlavorResources[] = $assetResource;
			}
			else 
			{
				KalturaLog::debug("flavorAsset->flavorParamsId [$flavorAsset->flavorParamsId]");
				$flavorAssets[$flavorAsset->flavorParamsId] = $flavorAsset;
				$assetResourceContainer->assetParamsId = $flavorAsset->flavorParamsId;
				$assetResourceContainer->resource = $assetResource;
				$resource->resources[] = $assetResourceContainer;
			}
		}

		//For each thumbnail in the item element we create a new thumb asset
		foreach ($item->thumbnail as $thumbElement)
		{
			$assetResource = $this->getResource($thumbElement);
			$assetResourceContainer = new KalturaAssetParamsResourceContainer();
			$thumbAsset = $this->getThumbAsset($thumbElement, $entry->ingestionProfileId);
			
			if(is_null($thumbAsset->thumbParamsId))
			{
				KalturaLog::debug("thumbAsset [". print_r($thumbAsset, true) . "]");
				$noParamsThumbAssets[] = $thumbAsset;
				$noParamsThumbResources[] = $assetResource;
			}
			else //we have a thumbParamsId so we add to the resources
			{
				KalturaLog::debug("thumbAsset->thumbParamsId [$thumbAsset->thumbParamsId]");
				$thumbAssets[$thumbAsset->thumbParamsId] = $thumbAsset;
				$assetResourceContainer->assetParamsId = $thumbAsset->thumbParamsId;
				$assetResourceContainer->resource = $assetResource;
				$resource->resources[] = $assetResourceContainer;
			}
		}

		//Throw exception in case of  max proccessed items and handle all exceptions there
		$createdEntryBulkUploadResult = $this->createUploadResult($item);

		if($this->exceededMaxRecordsEachRun) // exit if we have proccessed max num of items
			return;
			
		$createdEntry = $this->sendItemAddData($entry, $resource, $noParamsFlavorAssets, $noParamsFlavorResources, $noParamsThumbAssets, $noParamsThumbResources);
			
		//Updates the bulk upload result for the given entry (with the status and other data)
		$this->updateEntriesResults(array($createdEntry), array($createdEntryBulkUploadResult));
		
		//Adds the additional data for the flavors and thumbs
		$this->handleFlavorAndThumbsAdditionalData($createdEntry->id, $flavorAssets, $thumbAssets);
				
		//Handles the plugin added data
		$pluginsInstances = KalturaPluginManager::getPluginInstances('IKalturaBulkUploadXmlHandler');
		
		//we need to impersonate the client so the plugins will have an impersonated client
		$this->impersonate();
		foreach($pluginsInstances as $pluginsInstance)
			$pluginsInstance->handleItemAdded($this->kClient, $createdEntry, $item);
	}
	
	/**
	 * 
	 * Sends the data using a multi requsest according to the given data
	 * @param KalturaBaseEntry $entry
	 * @param KalturaAssetsParamsResourceContainers $resource
	 * @param array $noParamsFlavorAssets
	 * @param array $noParamsFlavorResources
	 * @param array $noParamsThumbAssets
	 * @param array $noParamsThumbResources
	 * @return $requestResults - the multi request result
	 */
	protected function sendItemAddData(KalturaBaseEntry $entry ,KalturaAssetsParamsResourceContainers $resource, array $noParamsFlavorAssets, array $noParamsFlavorResources, array $noParamsThumbAssets, array $noParamsThumbResources)
	{
		$this->startMultiRequest(true);
		
		KalturaLog::debug("Resource is: " . print_r($resource, true));
		
		if(!count($resource->resources))
			$resource = null;
			
		$this->kClient->baseEntry->add($entry, $resource, $entry->type); // Adds the entry
		$newEntryId = "{1:result:id}";
		
		foreach($noParamsFlavorAssets as $index => $flavorAsset) // Adds all the entry flavors
		{
			$flavorResource = $noParamsFlavorResources[$index];
			$this->kClient->flavorAsset->add($newEntryId, $flavorAsset, $flavorResource);
		}
	
		foreach($noParamsThumbAssets as $index => $thumbAsset) //Adds the entry thumb assests
		{
			$thumbResource = $noParamsThumbResources[$index];
			$this->kClient->thumbAsset->add($newEntryId, $thumbAsset, $thumbResource);
		}
				
		$requestResults = $this->kClient->doMultiRequest();;
		
		$createdEntry = reset($requestResults);
		
		if(is_null($createdEntry)) //checks that the entry was created
		{
			throw new KalturaBulkUploadXmlException("The entry wasn't created", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
		}
		
		if(!($createdEntry instanceof KalturaObjectBase)) // if the entry is not kaltura object (in case of errors)
		{
			throw new KalturaBulkUploadXmlException("The entry wasn't created requestResults [$requestResults]", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
		}
		
		if(!isset($createdEntry->id) || empty($createdEntry->id)) //checks that the entry id was set and it is not empty
		{
			throw new KalturaBulkUploadXmlException("The entry id [$createdEntry->id] wasn't set", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
		}
		
		return $createdEntry;
	}
	
	/**
	 * 
	 * Handles the adding od additional data to the preciously created flavors and thumbs 
	 * @param int $createdEntryId
	 * @param array $flavorAssets
	 * @param array $thumbAssets
	 */
	protected function handleFlavorAndThumbsAdditionalData($createdEntryId, $flavorAssets, $thumbAssets)
	{
		$this->startMultiRequest(true);
		//Gets the created thumbs and flavors
		$this->kClient->flavorAsset->getByEntryId($createdEntryId);
		$this->kClient->thumbAsset->getByEntryId($createdEntryId);
		$result = $this->kClient->doMultiRequest();
			
		$createdFlavorAssets = $result[0]; 
		$createdThumbAssets =  $result[1];
				
		$this->startMultiRequest(true);
		///For each flavor asset that we just added without his data then we need to update his additional data
		foreach($createdFlavorAssets as $createdFlavorAsset)
		{
			if(is_null($createdFlavorAsset->flavorParamsId)) //no flavor params to the flavor asset
				continue;
				
			if(!isset($flavorAssets[$createdFlavorAsset->flavorParamsId])) // We don't have the flavor in our dictionary
				continue;
				
			$flavorAsset = $flavorAssets[$createdFlavorAsset->flavorParamsId];
			$this->kClient->flavorAsset->update($createdFlavorAsset->id, $flavorAsset);
		}
			
		foreach($createdThumbAssets as $createdThumbAsset)
		{
			if(is_null($createdThumbAsset->thumbParamsId))
				continue;
				
			if(!isset($thumbAssets[$createdThumbAsset->thumbParamsId]))
				continue;
				
			$thumbAsset = $thumbAssets[$createdThumbAsset->thumbParamsId];
			$this->kClient->thumbAsset->update($createdThumbAsset->id, $thumbAsset);
		}
		
		$requestResults = $this->kClient->doMultiRequest();
				
		return $requestResults;
	}
	
	/**
	 * 
	 * returns a flavor asset form the current content element
	 * @param SimpleXMLElement $contentElement
	 * @return KalturaFlavorAsset
	 */
	protected function getFlavorAsset(SimpleXMLElement $contentElement, $conversionProfileId)
	{
		$flavorAsset = new KalturaFlavorAsset(); //we create a new asset (for add)
		$flavorAsset->flavorParamsId = $this->getFlavorParamsId($contentElement, $conversionProfileId, true);
		$flavorAsset->tags = $this->implodeChildElements($contentElement->tags);
			
		return $flavorAsset;
	}
	
	/**
	 * 
	 * returns a thumbnail asset form the current thumbnail element
	 * @param SimpleXMLElement $thumbElement
	 * @param int $conversionProfileId - The converrsion profile id 
	 * @return KalturaThumbAsset
	 */
	protected function getThumbAsset(SimpleXMLElement $thumbElement, $conversionProfileId)
	{
		$thumbAsset = new KalturaThumbAsset();
		$thumbAsset->thumbParamsId = $this->getThumbParamsId($thumbElement, $conversionProfileId);
		
		if(isset($thumbElement["isDefault"]) && $thumbElement["isDefault"] == 'true') // if the attribute is set to true we add the is default tag to the thumb
			$thumbAsset->tags = self::DEFAULT_THUMB_TAG;
		
		$thumbAsset->tags = $this->implodeChildElements($thumbElement->tags, $thumbAsset->tags);
		
		return $thumbAsset;
	}
	
	/**
	 * 
	 * Validates if the resource is valid
	 * @param KalturaResource $resource
	 * @param SimpleXMLElement $elementToSearchIn
	 */
	protected function validateResource(KalturaResource $resource, SimpleXMLElement $elementToSearchIn)
	{
		//We only check for filesize and check sum in local files 
		if($resource instanceof KalturaLocalFileResource)
		{
			$filePath = $resource->localFilePath;
			
			if(is_null($filePath))
			{
				throw new KalturaBulkUploadXmlException("Can't validate file as file path is null", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
			}
				
			if(isset($elementToSearchIn->fileChecksum)) //Check checksum if exists
			{
				KalturaLog::debug("Validating checksum");
				if($elementToSearchIn->fileChecksum['type'] == 'sha1')
				{
					 $checksum = sha1_file($filePath);
				}
				else
				{
					$checksum = md5_file($filePath);
				}
				
				$xmlChecksum = (string)$elementToSearchIn->fileChecksum;

				if($xmlChecksum != $checksum)
				{
					throw new KalturaBulkUploadXmlException("File checksum is invalid for file [$filePath], Xml checksum [$xmlChecksum], actual checksum [$checksum]", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
			}
			
			if(isset($elementToSearchIn->fileSize)) //Check checksum if exists
			{
				KalturaLog::debug("Validating file size");
				
				$fileSize = filesize($filePath);
				$xmlFileSize = (int)$elementToSearchIn->fileSize;
				if($xmlFileSize != $fileSize)
				{
					throw new KalturaBulkUploadXmlException("File size is invalid for file [$filePath], Xml size [$xmlFileSize], actual size [$fileSize]", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
			}
		}
	}
	
	/**
	 * 
	 * Gets an item and returns the resource
	 * @param SimpleXMLElement $elementToSearchIn
	 * @return KalturaResource - the resource located in the given element
	 */
	protected function getResource(SimpleXMLElement $elementToSearchIn)
	{
		$resource = $this->getResourceInstance($elementToSearchIn);
		$this->validateResource($resource, $elementToSearchIn);
										
		return $resource;
	}
	
	/**
	 * 
	 * Returns the right resource instance for the source content of the item
	 * @param SimpleXMLElement $elementToSearchIn
	 * @return KalturaResource - the resource located in the given element
	 */
	protected function getResourceInstance(SimpleXMLElement $elementToSearchIn)
	{
		$resource = null;
			
		if(isset($elementToSearchIn->localFileContentResource))
		{
			KalturaLog::debug("Resource is : localFileContentResource");
			$resource = new KalturaLocalFileResource();
			$localContentResource = $elementToSearchIn->localFileContentResource;
			$resource->localFilePath = kXml::getXmlAttributeAsString($localContentResource, "filePath");
		}
		elseif(isset($elementToSearchIn->urlContentResource))
		{
			KalturaLog::debug("Resource is : urlContentResource");
			$resource = new KalturaUrlResource();
			$urlContentResource = $elementToSearchIn->urlContentResource;
			$resource->url = kXml::getXmlAttributeAsString($urlContentResource, "url");
		}
		elseif(isset($elementToSearchIn->remoteStorageContentResource))
		{
			KalturaLog::debug("Resource is : remoteStorageContentResource");
			$resource = new KalturaRemoteStorageResource();
			$remoteContentResource = $elementToSearchIn->remoteStorageContentResource;
			$resource->url = kXml::getXmlAttributeAsString($remoteContentResource, "url");
			$resource->storageProfileId = $this->getStorageProfileId($remoteContentResource);
		}
		elseif(isset($elementToSearchIn->entryContentResource))
		{
			KalturaLog::debug("Resource is : entryContentResource");
			$resource = new KalturaEntryResource();
			$entryContentResource = $elementToSearchIn->entryContentResource;
			$resource->entryId = kXml::getXmlAttributeAsString($entryContentResource, "entryId");
			$resource->flavorParamsId = $this->getFlavorParamsId($entryContentResource, false);
		}
		elseif(isset($elementToSearchIn->assetContentResource))
		{
			KalturaLog::debug("Resource is : assetContentResource");
			$resource = new KalturaAssetResource();
			$assetContentResource = $elementToSearchIn->assetContentResource;
			$resource->assetId = kXml::getXmlAttributeAsString($assetContentResource, "assetId");
		}
		
		return $resource;
	}
	
	/**
	 * 
	 * Gets the flavor params id from the given element
	 * @param $elementToSearchIn - The element to search in
	 * @param $conversionProfileId - The conversion profile on the item
	 * @return int - The id of the flavor params
	 */
	protected function getFlavorParamsId(SimpleXMLElement $elementToSearchIn, $conversionProfileId, $isAttribute = true)
	{
		return $this->getAssetParamsId($elementToSearchIn, $conversionProfileId, $isAttribute, 'flavor');
	}
	
	/**
	 * 
	 * Gets the flavor params id from the given element
	 * @param SimpleXMLElement $elementToSearchIn - The element to search in
	 * @param int $conversionProfileId - The conversion profile on the item
	 * @param bool $isAttribute
	 * @param string $assetType flavor / thumb
	 * @return int - The id of the flavor params
	 */
	protected function getAssetParamsId(SimpleXMLElement $elementToSearchIn, $conversionProfileId, $isAttribute, $assetType)
	{
		$assetParams = "{$assetType}Params";
		$assetParamsId = "{$assetParams}Id";
		$assetParamsName = null;
		
		if($isAttribute)
		{
			if(isset($elementToSearchIn[$assetParamsId]))
				return (int)$elementToSearchIn[$assetParamsId];
	
			if(isset($elementToSearchIn[$assetParams]))
				$assetParamsName = $elementToSearchIn[$assetParams];
		}
		else
		{
			if(isset($elementToSearchIn->$assetParamsId))
				return (int)$elementToSearchIn->$assetParamsId;
	
			if(isset($elementToSearchIn->$assetParams))
				$assetParamsName = $elementToSearchIn->$assetParams;
		}
			
		if(!$assetParamsName)
			return null;
			
		if(isset($this->assetParamsNameToIdPerConversionProfile[$conversionProfileId]))
			$this->initAssetParamsNameToId($conversionProfileId);
			
		if(isset($this->assetParamsNameToIdPerConversionProfile[$conversionProfileId][$assetParamsName]))
			return $this->assetParamsNameToIdPerConversionProfile[$conversionProfileId][$assetParamsName];
			
		return null;
	}
	
	/**
	 * 
	 * Gets the ingestion profile id in this order: 
	 * 1.from the element 2.from the data of the bulk 3.use default)
	 * @param SimpleXMLElement $elementToSearchIn
	 */
	protected function getIngestionProfileId(SimpleXMLElement $elementToSearchIn)
	{
		$conversionProfileId = $this->getIngestionProfileIdFromElement($elementToSearchIn);
		
		KalturaLog::debug("conversionProfileid from element [ $conversionProfileId ]");
		
		if(is_null($conversionProfileId)) // if we didn't set it in the item element
		{
			$conversionProfileId = $this->data->conversionProfileId;
			KalturaLog::debug("conversionProfileid from data [ $conversionProfileId ]");
		}
		
		if(is_null($conversionProfileId)) // if we didn't set it in the item element
		{
			$this->impersonate();

			//Gets the user default conversion
			if(!isset($this->defaultIngestionProfileId))
			{
				$conversionProfile = $this->kClient->conversionProfile->getDefault();
				$this->defaultIngestionProfileId = $conversionProfile->id;
			}
			
			$conversionProfileId = $this->defaultIngestionProfileId;
			KalturaLog::debug("conversionProfileid from default [ $conversionProfileId ]"); 
		}
		
		return $conversionProfileId;
	}
	
	/**
	 * 
	 * Gets the coversion profile id from the given element
	 * @param $elementToSearchIn - The element to search in
	 * @return int - The id of the ingestion profile params
	 */
	protected function getIngestionProfileIdFromElement(SimpleXMLElement $elementToSearchIn)
	{
		if(isset($elementToSearchIn->ingestionProfileId))
			return (int)$elementToSearchIn->ingestionProfileId;

		if(!isset($elementToSearchIn->ingestionProfile))
			return null;	
			
		if(!isset($this->ingestionProfileNameToId["$elementToSearchIn->ingestionProfile"]))
		{
			$this->initIngestionProfileNameToId();
		}
			
		if(isset($this->ingestionProfileNameToId["$elementToSearchIn->ingestionProfile"]))
			return $this->ingestionProfileNameToId["$elementToSearchIn->ingestionProfile"];

		return null;
	}
		
	/**
	 * 
	 * Gets the thumb params id from the given element
	 * @param $elementToSearchIn - The element to search in
	 * @param $conversionProfileId - The conversion profile id
	 * @param $isAttribute - bool
	 * @return int - The id of the thumb params
	 */
	protected function getThumbParamsId(SimpleXMLElement $elementToSearchIn, $conversionProfileId, $isAttribute = true)
	{
		return $this->getAssetParamsId($elementToSearchIn, $conversionProfileId, $isAttribute, 'thumb');
	}
		
	/**
	 * 
	 * Gets the flavor params id from the source content element
	 * @param $elementToSearchIn - The element to search in
	 * @return int - The id of the flavor params
	 */
	protected function getAccessControlId(SimpleXMLElement $elementToSearchIn)
	{
		if(isset($elementToSearchIn->accessControlId))
			return (int)$elementToSearchIn->accessControlId;

		if(!isset($elementToSearchIn->accessControl))
			return null;	
			
		if(is_null($this->accessControlNameToId))
		{
			$this->initAccessControlNameToId();
		}
			
		if(isset($this->accessControlNameToId["$elementToSearchIn->accessControl"]))
			return trim($this->accessControlNameToId["$elementToSearchIn->accessControl"]);
			
		return null;
	}
		
	/**
	 * 
	 * 
	 * Gets the storage profile id from the source content element
	 * @param $elementToSearchIn - The element to search in
	 * @return int - The id of the storage profile
	 */
	protected function getStorageProfileId(SimpleXMLElement $elementToSearchIn)
	{
		if(isset($elementToSearchIn->storageProfileId))
			return (int)$elementToSearchIn->storageProfileId;

		if(!isset($elementToSearchIn->storageProfile))
			return null;	
			
		if(is_null($this->storageProfileNameToId))
		{
			$this->initStorageProfileNameToId();
		}
			
		if(isset($this->storageProfileNameToId["$elementToSearchIn->storageProfile"]))
			return trim($this->storageProfileNameToId["$elementToSearchIn->storageProfile"]);
			
		return null;
	}
	
	/**
	 * 
	 * Inits the array of flavor params name to Id (with all given flavor params)
	 * @param $coversionProfileId - The conversion profile for which we ini the arrays for
	 */
	protected function initAssetParamsNameToId($conversionProfileId)
	{
		$this->impersonate();
		
		$allFlavorParams = $this->kClient->conversionProfile->listAssetParams($conversionProfileId);
		$allFlavorParams = $allFlavorParams->objects;
		
		KalturaLog::debug("allFlavorParams [" . print_r($allFlavorParams, true). "]");
		
		foreach ($allFlavorParams as $flavorParams)
		{
			if(!empty($flavorParams->systemName))
				$this->assetParamsNameToIdPerConversionProfile[$conversionProfileId][$flavorParams->systemName] = $flavorParams->id;
		}
		
		KalturaLog::debug("new assetParamsNameToIdPerConversionProfile [" . print_r($this->assetParamsNameToIdPerConversionProfile, true). "]");
	}

	/**
	 * 
	 * Inits the array of access control name to Id (with all given flavor params)
	 */
	protected function initAccessControlNameToId()
	{
		$this->impersonate();
		$allAccessControl = $this->kClient->accessControl->listAction(null, null);
		$allAccessControl = $allAccessControl->objects;
		
		KalturaLog::debug("allAccessControl [" . print_r($allAccessControl, true). "]");
		
		foreach ($allAccessControl as $accessControl)
		{
			if(!is_null($accessControl->systemName))
				$this->accessControlNameToId[$accessControl->systemName] = $accessControl->id;
		}
		
		KalturaLog::debug("new accessControlNameToId [" . print_r($this->accessControlNameToId, true). "]");
	}

	/**
	 * 
 	 * Inits the array of access control name to Id (with all given flavor params)
 	 * @param $entryId - the entry id to take the flavor assets from
	 */
	protected function initAssetIdToAssetParamsId($entryId)
	{
		$this->impersonate();
		
		$allFlavorAssets = $this->kClient->flavorAsset->getByEntryId($entryId);
		$allThumbAssets = $this->kClient->thumbAsset->getByEntryId($entryId);
						
		KalturaLog::debug("allFlavorAssets [" . print_r($allFlavorAssets, true). "]");
		KalturaLog::debug("allThumbAssets [" . print_r($allThumbAssets, true). "]");
		
		foreach ($allFlavorAssets as $flavorAsset)
		{
			if(!is_null($flavorAsset->id)) //should always have an id
				$this->assetIdToAssetParamsId[$entryId][$flavorAsset->id] = $flavorAsset->flavorParamsId;
		}
		
		foreach ($allThumbAssets as $thumbAsset) 
		{
			if(!is_null($thumbAsset->id)) //should always have an id
				$this->assetIdToAssetParamsId[$entryId][$thumbAsset->id] = $thumbAsset->thumbParamsId;
		}
		
		KalturaLog::debug("new assetIdToAssetParamsId [" . print_r($this->assetIdToAssetParamsId, true). "]");
	}
	
	/**
	 * 
	 * Inits the array of conversion profile name to Id (with all given flavor params)
	 */
	protected function initIngestionProfileNameToId()
	{
		$this->impersonate();
		$allIngestionProfile = $this->kClient->conversionProfile->listAction(null, null);
		$allIngestionProfile = $allIngestionProfile->objects;
		
		KalturaLog::debug("allIngestionProfile [" . print_r($allIngestionProfile,true) ." ]");
		
		foreach ($allIngestionProfile as $ingestionProfile)
		{
			$systemName = $ingestionProfile->systemName;
			if(!empty($systemName))
				$this->ingestionProfileNameToId[$systemName] = $ingestionProfile->id;
		}
		
		KalturaLog::debug("new ingestionProfileNameToId [" . print_r($this->ingestionProfileNameToId, true). "]");
	}

	/**
	 * 
	 * Inits the array of storage profile to Id (with all given flavor params)
	 */
	protected function initStorageProfileNameToId()
	{
		$this->impersonate();
		$allStorageProfiles = $this->kClient->storageProfile->listAction(null, null);
		$allStorageProfiles = $allStorageProfiles->objects;
		
		KalturaLog::debug("allStorageProfiles [" . print_r($allStorageProfiles,true) ." ]");
		
		foreach ($allStorageProfiles as $storageProfile)
		{
			if(!is_null($storageProfile->systemName))
				$this->accessControlNameToId["$storageProfile->systemName"] = $storageProfile->id;
		}
		
		KalturaLog::debug("new accessControlNameToId [" . print_r($this->accessControlNameToId, true). "]");
	}
		
	/**
  	 * Creates and returns a new media entry for the given job data and bulk upload result object
	 * @param SimpleXMLElement $bulkUploadResult
	 * @return KalturaBaseEntry
	 */
	protected function createEntryFromItem(SimpleXMLElement $item)
	{
		//Create the new media entry and set basic values
		$entry = $this->getEntryInstanceByType($item->type);

		$entry->name = (string)$item->name;
		$entry->description = (string)$item->description;
		$entry->tags = $this->implodeChildElements($item->tags);
		$entry->categories = $this->implodeChildElements($item->categories);
		$entry->userId = (string)$item->userId;;
		$entry->licenseType = (string)$item->licenseType;
		$entry->accessControlId =  $this->getAccessControlId($item);
		$entry->startDate = self::parseFormatedDate((string)$item->startDate);
		if(!$entry->startDate) //if start date is not set we will use now
		{
			$entry->startDate = date();
		}
		
		$entry->endDate = self::parseFormatedDate((string)$item->endDate);
		$entry->type = (int)$item->type;
		$entry->ingestionProfileId = $this->getIngestionProfileId($item);
		
		return $entry;
	}
	
	/**
	 * 
	 * Returns the right entry instace by the given item type
	 * @param int $item
	 * @return KalturaBaseEntry 
	 */
	protected function getEntryInstanceByType($type)
	{
		switch(trim($type))
		{
			case KalturaEntryType::MEDIA_CLIP :
				return new KalturaMediaEntry();
			case KalturaEntryType::DATA:
				return new KalturaDataEntry();
			case KalturaEntryType::DOCUMENT:
				return new KalturaDocumentEntry();
			case KalturaEntryType::LIVE_STREAM:
				return new KalturaLiveStreamEntry();
			case KalturaEntryType::MIX:
				return new KalturaMixEntry();
			case KalturaEntryType::PLAYLIST:
				return new KalturaPlaylist();  
			default:
				return new KalturaBaseEntry(); 
		}	
	}

	/**
	 * 
	 * Handles the type additional data for the given item
	 * @param KalturaBaseEntry $media
	 * @param SimpleXMLElement $item
	 */
	protected function handleTypedElement(KalturaBaseEntry $entry, SimpleXMLElement $item)
	{
		// TODO take type from ingestion profile default entry
		switch ($entry->type)
		{
			case KalturaEntryType::MEDIA_CLIP:
				$this->setMediaElementValues($entry, $item);
				break;
				
			case KalturaEntryType::MIX:
				$this->setMixElementValues($entry, $item);
				break;
				
			case KalturaEntryType::DATA:
				$this->setDataElementValues($entry, $item);
				break;
				
			case KalturaEntryType::DOCUMENT:
				$this->setDocumentElementValues($entry, $item);
				break;
				
			case KalturaEntryType::LIVE_STREAM:
				$this->setLiveStreamElementValues($entry, $item);
				break;
			
			case KalturaEntryType::PLAYLIST:
				$this->setPlaylistElementValues($entry, $item);
				break;
				
			default:
				$entry->type = KalturaEntryType::AUTOMATIC;
				break;
		}
	}

	/**
	 * 
	 * Check if the item type and the type element are matching
	 * @param SimpleXMLElement $item
	 * @throws KalturaBatchException - KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED ; 
	 */
	protected function validateTypeToTypedElement(SimpleXMLElement $item) 
	{
		$typeNumber = $item->type;
		$typeNumber = trim($typeNumber);
		
		if(isset($item->media) && $item->type != KalturaEntryType::MEDIA_CLIP)
			throw new KalturaBulkUploadXmlException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
			
		if(isset($item->mix) && $item->type != KalturaEntryType::MIX)
			throw new KalturaBulkUploadXmlException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
			
		if(isset($item->playlist) && $item->type != KalturaEntryType::PLAYLIST)
			throw new KalturaBulkUploadXmlException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);

		if(isset($item->document) && $item->type != KalturaEntryType::DOCUMENT)
			throw new KalturaBulkUploadXmlException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);

		if(isset($item->liveStream) && $item->type != KalturaEntryType::LIVE_STREAM)
			throw new KalturaBulkUploadXmlException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
		
		if(isset($item->data) && $item->type != KalturaEntryType::DATA)
			throw new KalturaBulkUploadXmlException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
	}

	/**
	 * 
	 * Sets the media values in the media entry according to the given item node
	 * @param KalturaMediaEntry $media 
	 * @param SimpleXMLElement $itemElement
	 */
	protected function setMediaElementValues(KalturaMediaEntry $media, SimpleXMLElement $itemElement)
	{
		$mediaElement = $itemElement->media;
		$media->mediaType = (int)$mediaElement->mediaType;
		$this->validateMediaTypes($media->mediaType);
	}

	/**
	 * 
	 * Sets the playlist values in the live stream entry according to the given item node
	 * @param KalturaPlaylist $playlistEntry 
	 * @param SimpleXMLElement $itemElement
	 */
	protected function setPlaylistElementValues(KalturaPlaylist $playlistEntry, SimpleXMLElement $itemElement)
	{
		$playlistElement = $itemElement->playlist;
		$playlistEntry->playlistType = (int)$playlistElement->playlistType;
		$playlistEntry->playlistContent = (string)$playlistElement->playlistContent;
	}
	
	/**
	 * 
	 * Sets the live stream values in the live stream entry according to the given item node
	 * @param KalturaLiveStreamEntry $liveStreamEntry 
	 * @param SimpleXMLElement $itemElement
	 */
	protected function setLiveStreamElementValues(KalturaLiveStreamEntry $liveStreamEntry, SimpleXMLElement $itemElement)
	{
		$liveStreamElement = $itemElement->liveStream;
		$liveStreamEntry->bitrates = (int)$liveStreamElement->bitrates;
		//What to do with those?
//		$liveStreamEntry->encodingIP1 = $dataElement->encodingIP1;
//		$liveStreamEntry->encodingIP2 = $dataElement->encodingIP2;
//		$liveStreamEntry->streamPassword = $dataElement->streamPassword
	}
	
	/**
	 * 
	 * Sets the data values in the data entry according to the given item node
	 * @param KalturaDataEntry $dataEntry 
	 * @param SimpleXMLElement $itemElement
	 */
	protected function setDataElementValues(KalturaDataEntry $dataEntry, SimpleXMLElement $itemElement)
	{
		$dataElement = $itemElement->media;
		$dataEntry->dataContent = (string)$dataElement->dataContent;
		$dataEntry->retrieveDataContentByGet = (bool)$dataElement->retrieveDataContentByGet;
	}
	
	/**
	 * 
	 * Sets the mix values in the mix entry according to the given item node
	 * @param KalturaMixEntry $mix 
	 * @param SimpleXMLElement $itemElement
	 */
	protected function setMixElementValues(KalturaMixEntry $mix, SimpleXMLElement $itemElement)
	{
		//TOOD: add support for the mix elements
		$mixElement = $itemElement->mix;
		$mix->editorType = $mixElement->editorType;
		$mix->dataContent = $mixElement->dataContent;
	}
		
	/**
	 * 
	 * Sets the document values in the media entry according to the given media node
	 * @param KalturaDocumentEntry $media 
	 * @param SimpleXMLElement $itemElement
	 */
	protected function setDocumentElementValues(KalturaDocumentEntry $document, SimpleXMLElement $itemElement)
	{
		$documentElement = $itemElement->document;
		$document->documentType = $documentElement->documentType;
	}
	
	/**
	 * 
	 * Checks if the media type and the type are valid
	 * @param KalturaMediaType $mediaType
	 */
	protected function validateMediaTypes($mediaType)
	{
		$mediaTypes = array(
			KalturaMediaType::LIVE_STREAM_FLASH,
			KalturaMediaType::LIVE_STREAM_QUICKTIME,
			KalturaMediaType::LIVE_STREAM_REAL_MEDIA,
			KalturaMediaType::LIVE_STREAM_WINDOWS_MEDIA
		);
		 
		//TODO: use this function
		if(in_array($mediaType, $mediaTypes))
			return false;
		
		if($mediaType == KalturaMediaType::IMAGE)
		{
			// TODO - make sure that there are no flavors or thumbnails in the XML
		}
		
		return true;
	}
	
	/**
	 * 
	 * Adds the given media entry to the given playlists in the element
	 * @param SimpleXMLElement $playlistsElement
	 */
	protected function addToPlaylists(SimpleXMLElement $playlistsElement)
	{
		foreach ($playlistsElement->children() as $playlistElement)
		{
			//TODO: Roni - add the media to the play list not supported 
			//AddToPlaylist();
		}
	}
	
	/**
	 * 
	 * Returns a comma seperated string with the values of the child nodes of the given element 
	 * @param SimpleXMLElement $element
	 */
	protected function implodeChildElements(SimpleXMLElement $element, $baseValues = null)
	{
		$ret = array();
		if($baseValues)
			$ret = explode(',', $baseValues);
		
		if(empty($element))
			return $baseValues;
		
		foreach ($element->children() as $child)
		{
			if(is_null($child))
				continue;
				
			$value = trim("$child");
			if($value)
				$ret[] = $value;
		}
		
		$ret = implode(',', $ret);
		KalturaLog::debug("The created string [$ret]");
		return $ret;
	}

	/**
	 * 
	 * Creates a new upload result object from the given SimpleXMLElement item
	 * @param SimpleXMLElement $item
	 */
	protected function createUploadResult(SimpleXMLElement $item)
	{
		//TODO: What should we write in the bulk upload result for update? 
		//only the changed parameters or just teh one theat was changed
		KalturaLog::debug("Creating upload result");
		KalturaLog::debug("this->handledRecordsThisRun [$this->handledRecordsThisRun], this->maxRecordsEachRun [$this->maxRecordsEachRun]");
		
		if($this->handledRecordsThisRun > $this->maxRecordsEachRun)
		{
			KalturaLog::debug("Setting exceededMaxRecordsEachRun to true");
			$this->exceededMaxRecordsEachRun = true;
			return; // exit if we have proccessed max num of itemse
		}
		
		$this->handledRecordsThisRun++;
		
		$bulkUploadResult = new KalturaBulkUploadResult();
		$bulkUploadResult->bulkUploadJobId = $this->job->id;
		
		$bulkUploadResult->lineIndex = $this->currentItem;
		$bulkUploadResult->partnerId = $this->job->partnerId;
		$bulkUploadResult->rowData = $item->asXml();
		$bulkUploadResult->entryStatus = KalturaEntryStatus::IMPORT;
		$bulkUploadResult->conversionProfileId = $this->getIngestionProfileId($item);
		$bulkUploadResult->accessControlProfileId = $this->getAccessControlId($item); 
		
		if(!is_numeric($bulkUploadResult->conversionProfileId))
			$bulkUploadResult->conversionProfileId = null;
			
		if(!is_numeric($bulkUploadResult->accessControlProfileId))
			$bulkUploadResult->accessControlProfileId = null;
			
		if(isset($item->startDate))
		{
			if((string)$item->startDate && !self::isFormatedDate((string)$item->startDate))
			{
				$bulkUploadResult->entryStatus = KalturaEntryStatus::ERROR_IMPORTING;
				$bulkUploadResult->errorDescription = "Invalid schedule start date {$item->startDate} on item $item->name";
			}
		}
		
		if(isset($item->endDate))
		{
			if((string)$item->endDate && !self::isFormatedDate((string)$item->endDate))
			{
				$bulkUploadResult->entryStatus = KalturaEntryStatus::ERROR_IMPORTING;
				$bulkUploadResult->errorDescription = "Invalid schedule end date {$item->endDate} on item $item->name";
			}
		}
		
		if($bulkUploadResult->entryStatus == KalturaEntryStatus::ERROR_IMPORTING)
		{
			$this->addBulkUploadResult($bulkUploadResult);
			return;
		}
		
		$bulkUploadResult->scheduleStartDate = self::parseFormatedDate((string)$item->startDate);
		$bulkUploadResult->scheduleEndDate = self::parseFormatedDate((string)$item->endDate);
		
		return $bulkUploadResult;
	}
	
	protected function getXsdFilePath()
	{
		return $this->xsdFilePath;
	}
	
	protected function setXsdFilePath($filePath)
	{
		$this->xsdFilePath = $filePath;
	}
}