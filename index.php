<?php

class_exists('CApi') or die();

set_include_path(__DIR__."/libs/" . PATH_SEPARATOR . get_include_path());


if (!class_exists('Google_Client'))
{
	include_once 'Google/Client.php';
	if (!class_exists('Google_Service_Drive'))
	{
		include_once 'Google/Service/Drive.php';
	}
}

class CFilestorageGoogleDrivePlugin extends AApiPlugin
{
	const StorageType = 3;
	const StorageTypeStr = 'google';
	const DisplayName = 'Google Drive';

	/* @var $oSocial \CSocial */
	public $oSocial = null;

	/**
	 * @param CApiPluginManager $oPluginManager
	 */
	public function __construct(CApiPluginManager $oPluginManager)
	{
		parent::__construct('1.0', $oPluginManager);
	}
 
	public function Init()
	{
		parent::Init();
		
		$this->AddCssFile('css/style.css');
		
		$this->AddHook('filestorage.get-external-storages', 'GetExternalStorage');
		$this->AddHook('filestorage.file-exists', 'FileExists');
		$this->AddHook('filestorage.get-file-info', 'GetFileInfo');
		$this->AddHook('filestorage.get-file', 'GetFile');
		$this->AddHook('filestorage.get-files', 'GetFiles');
		$this->AddHook('filestorage.create-folder', 'CreateFolder');
		$this->AddHook('filestorage.create-file', 'CreateFile');
		$this->AddHook('filestorage.create-public-link', 'CreatePublicLink');
		$this->AddHook('filestorage.delete', 'Delete');
		$this->AddHook('filestorage.rename', 'Rename');
		$this->AddHook('filestorage.move', 'Move');
		$this->AddHook('filestorage.copy', 'Copy');		
	}
	
	protected function GetSocial($oAccount)
	{
		if (!isset($this->oSocial))
		{
			/* @var $oApiSocial \CApiSocialManager */
			$oApiSocial = \CApi::Manager('social');
			$mResult = $oApiSocial->GetSocial($oAccount->IdAccount, self::StorageTypeStr);
			if ($mResult !== null && $mResult->IssetScope('filestorage'))
			{
				$this->oSocial = $mResult;
			}
		}
		return $this->oSocial;
	}

	protected function UpdateSocial($oSocial)
	{
		$bResult = false;
		if (isset($oSocial))
		{
			/* @var $oApiSocial \CApiSocialManager */
			$oApiSocial = \CApi::Manager('social');
			$bResult = $oApiSocial->UpdateSocial($this->oSocial);
			if ($bResult)
			{
				$this->oSocial = $oSocial;
			}
		}
		return $bResult;
	}
	
	protected function GetClient($oAccount, $sType)
	{
		$mResult = false;
		if ($sType === self::StorageTypeStr)
		{
			/* @var $oTenant \CTenant */
			$oTenant = null;
			$oApiTenants = \CApi::Manager('tenants');
			if ($oAccount && $oApiTenants)
			{
				$oTenant = (0 < $oAccount->IdTenant) ? $oApiTenants->GetTenantById($oAccount->IdTenant) :
					$oApiTenants->GetDefaultGlobalTenant();
			}
			
			/* @var $oSocial \CSocial */
			$oSocial = $this->GetSocial($oAccount);
			
			$oTenantSocial = null;
			if ($oTenant)
			{
				/* @var $oTenantSocial \CSocial */
				$oTenantSocial = $oTenant->GetSocialByName('google');
			}

			if ($oSocial && $oTenantSocial && $oTenantSocial->SocialAllow && $oTenantSocial->IssetScope('filestorage'))
			{
				$oClient = new Google_Client();
				$oClient->setClientId($oTenantSocial->SocialId);
				$oClient->setClientSecret($oTenantSocial->SocialSecret);
				$oClient->addScope('https://www.googleapis.com/auth/userinfo.email');
				$oClient->addScope('https://www.googleapis.com/auth/userinfo.profile');
				$oClient->addScope("https://www.googleapis.com/auth/drive");
				$bRefreshToken = false;
				try
				{
					$oClient->setAccessToken($oSocial->AccessToken);
				}
				catch (Exception $oException)
				{
					$bRefreshToken = true;
				}
				if ($oClient->isAccessTokenExpired() || $bRefreshToken) 
				{
					$oClient->refreshToken($oSocial->RefreshToken);
					$oSocial->AccessToken = $oClient->getAccessToken();
					$this->UpdateSocial($oSocial);
				}				
				if ($oClient->getAccessToken())
				{
					$mResult = $oClient;
				}
			}
		}
		
		return $mResult;
	}	
	
	public function GetExternalStorage($oAccount, &$aResult)
	{
		if ($this->GetSocial($oAccount))
		{
			$aResult[] = array(
				'Type' => self::StorageTypeStr,
				'DisplayName' => self::DisplayName
			);
		}
	}	
	
	public function FileExists($oAccount, $sType, $sPath, $sName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bBreak = true;
		}
	}	
	
	/**
	 * @param array $aData
	 */
	protected function PopulateFileInfo($sType, $sPath, $oFile)
	{
		$bResult = false;
		if ($oFile)
		{
			$bResult /*@var $bResult \CFileStorageItem */ = new  \CFileStorageItem();
			$bResult->IsExternal = true;
			$bResult->TypeStr = $sType;
			$bResult->IsFolder = ($oFile->mimeType === "application/vnd.google-apps.folder");
			$bResult->Id = $oFile->id;
			$bResult->Name = $oFile->title;
			$bResult->Path = '';
			$bResult->Size = $oFile->fileSize;
			$bResult->FullPath = $oFile->id;

//				$oItem->Owner = $oSocial->Name;
			$bResult->LastModified = date_timestamp_get(date_create($oFile->createdDate));
			$bResult->Hash = \CApi::EncodeKeyValues(array(
				'Type' => $sType,
				'Path' => $sPath,
				'Name' => $bResult->Id,
				'Size' => $bResult->Size
			));
		}

		return $bResult;
	}	
	
	/**
	 * @param \CAccount $oAccount
	 */
	public function GetFileInfo($oAccount, $sType, $sPath, $sName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bBreak = true;
			$bResult = false;
			$oDrive = new Google_Service_Drive($oClient);
			$oFile = $oDrive->files->get($sName);
			$bResult = $this->PopulateFileInfo($sType, $sPath, $oFile);
		}
	}	

	public function GetFile($oAccount, $sType, $sPath, $sName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bResult = false;
			$bBreak = true;
			$oDrive = new Google_Service_Drive($oClient);
			$oFile = $oDrive->files->get($sName);
			
			\api_Utils::PopulateGoogleDriveFileInfo($oFile);
			$oRequest = new Google_Http_Request($oFile->downloadUrl, 'GET', null, null);
            $oClientAuth = $oClient->getAuth();
            $oClientAuth->sign($oRequest);
            $oHttpRequest = $oClientAuth->authenticatedRequest($oRequest);			
			if ($oHttpRequest->getResponseHttpCode() === 200) 
			{
				$bResult = fopen('php://memory','r+');
				fwrite($bResult, $oHttpRequest->getResponseBody());
				rewind($bResult);
			} 
		}
	}	
	
	public function GetFiles($oAccount, $sType, $sPath, $sPattern, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bResult = array();
			$bBreak = true;
			$oDrive = new Google_Service_Drive($oClient);
			$sPath = ltrim(basename($sPath), '/');
			
			$aFileItems = array();
			$sPageToken = NULL;			

			if (empty($sPath))
			{
				$sPath = 'root';
			}
			
			$sQuery  = "'".$sPath."' in parents and trashed = false";
			if (!empty($sPattern))
			{
				$sQuery .= " and title contains '".$sPattern."'";
			}
			
			do 
			{
				try 
				{
					$aParameters = array('q' => $sQuery);
					if ($sPageToken) 
					{
						$aParameters['pageToken'] = $sPageToken;
					}

					$oFiles = $oDrive->files->listFiles($aParameters);
					$aFileItems = array_merge($aFileItems, $oFiles->getItems());
					$sPageToken = $oFiles->getNextPageToken();
				} 
				catch (Exception $e) 
				{
					$sPageToken = NULL;
				}
			} 
			while ($sPageToken);			
			
			foreach($aFileItems as $oChild) 
			{
				$oItem /*@var $oItem \CFileStorageItem */ = $this->PopulateFileInfo($sType, $sPath, $oChild);
				if ($oItem)
				{
					$bResult[] = $oItem;
				}
			}
		}
	}	

	public function CreateFolder($oAccount, $sType, $sPath, $sFolderName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bResult = false;
			$bBreak = true;

			$folder = new Google_Service_Drive_DriveFile();
			$folder->setTitle($sFolderName);
			$folder->setMimeType('application/vnd.google-apps.folder');

			// Set the parent folder.
			if ($sPath != null) 
			{
			  $parent = new Google_Service_Drive_ParentReference();
			  $parent->setId($sPath);
			  $folder->setParents(array($parent));
			}
			
			$oDrive = new Google_Service_Drive($oClient);
			try 
			{
				$oDrive->files->insert($folder, array());
				$bResult = true;
			} 
			catch (Exception $ex) 
			{
				$bResult = false;
			}
		}
	}	

	public function CreateFile($oAccount, $sType, $sPath, $sFileName, $mData, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bResult = false;
			$bBreak = true;

			$sMimeType = \MailSo\Base\Utils::MimeContentType($sFileName);
			$file = new Google_Service_Drive_DriveFile();
			$file->setTitle($sFileName);
			$file->setMimeType($sMimeType);

			$sPath = trim($sPath, '/');
			// Set the parent folder.
			if ($sPath != null) 
			{
			  $parent = new Google_Service_Drive_ParentReference();
			  $parent->setId($sPath);
			  $file->setParents(array($parent));
			}
			
			$oDrive = new Google_Service_Drive($oClient);
			try 
			{
				$sData = '';
				if (is_resource($mData))
				{
					rewind($mData);
					$sData = stream_get_contents($mData);
				}
				else
				{
					$sData = $mData;
				}
				$oDrive->files->insert($file, array(
					'data' => $sData,
					'mimeType' => $sMimeType,
					'uploadType' => 'media'
				));
				$bResult = true;
			} 
			catch (Exception $ex) 
			{
				$bResult = false;
			}
		}
	}	

	public function CreatePublicLink($oAccount, $sType, $sPath, $sName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bBreak = true;
		}
	}	

	public function Delete($oAccount, $sType, $sPath, $sName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bResult = false;
			$bBreak = true;
			$oDrive = new Google_Service_Drive($oClient);

			try 
			{
				$oDrive->files->trash($sName);
				$bResult = true;
			} 
			catch (Exception $ex) 
			{
				$bResult = false;
			}
		}
	}	

	public function Rename($oAccount, $sType, $sPath, $sName, $sNewName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sType);
		if ($oClient)
		{
			$bResult = false;
			$bBreak = true;
			$oDrive = new Google_Service_Drive($oClient);
			// First retrieve the file from the API.
			$file = $oDrive->files->get($sName);

			// File's new metadata.
			$file->setTitle($sNewName);

			$additionalParams = array();

			try 
			{
				$oDrive->files->update($sName, $file, $additionalParams);
				$bResult = true;
			} 
			catch (Exception $ex) 
			{
				$bResult = false;
			}
		}
	}	

	public function Move($oAccount, $sFromType, $sToType, $sFromPath, $sToPath, $sName, $sNewName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sFromType);
		if ($oClient)
		{
			$bResult = false;
			$bBreak = true;

			$sFromPath = $sFromPath === '' ?  'root' :  trim($sFromPath, '/');
			$sToPath = $sToPath === '' ?  'root' :  trim($sToPath, '/');
			
			$oDrive = new Google_Service_Drive($oClient);
			$oFile = $oDrive->files->get($sName);
                        
			$parent = new Google_Service_Drive_ParentReference();
			$parent->setId($sToPath);

//			$oFile->setTitle($sNewName);
			$oFile->setParents(array($parent));

			try 
			{
				$oDrive->files->patch($sName, $oFile);
				$bResult = true;
			} 
			catch (Exception $ex) 
			{
				$bResult = false;
			}
                }	
        }
        	

	public function Copy($oAccount, $sFromType, $sToType, $sFromPath, $sToPath, $sName, $sNewName, &$bResult, &$bBreak)
	{
		$oClient = $this->GetClient($oAccount, $sFromType);
		if ($oClient)
		{
			$bResult = false;
			$bBreak = true;
			$oDrive = new Google_Service_Drive($oClient);
                        
			$sToPath = $sToPath === '' ?  'root' :  trim($sToPath, '/');

			$parent = new Google_Service_Drive_ParentReference();
			$parent->setId($sToPath);

			$copiedFile = new Google_Service_Drive_DriveFile();
//			$copiedFile->setTitle($sNewName);
			$copiedFile->setParents(array($parent));

			try 
			{
				$oDrive->files->copy($sName, $copiedFile);
				$bResult = true;
			} 
			catch (Exception $ex) 
			{
				$bResult = false;
			}
		}
	}	
}

return new CFilestorageGoogleDrivePlugin($this);