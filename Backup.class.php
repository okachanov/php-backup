<?php

/*
	Backup class
	http://vi.germis.name
 */

class BackupJob {

	protected $oMailer;
	protected $aMailParams;
	
	protected $sHost;
	protected $sDatbase;
	protected $sUser;
	protected $sPassword;
	protected $sDestanationFolder;
	
	protected $dbConnection;
	protected $aErrors;
	
	public $sBackupMethod;
	public $bOk;
		
	//*********************************************************************************
	public function BackupJob($sHost, $sDatbase, $sUser, $sPassword, $sFolder = null){
		$this->aErrors	= array();
		$this->bOk		= true;
		
		$this->sHost				= $sHost;
		$this->sDatbase				= $sDatbase;
		$this->sUser				= $sUser;
		$this->sPassword			= $sPassword;
		
		if(empty($sFolder)) 
			$sFolder = (dirname(__FILE__));
		$this->sDestanationFolder	= $sFolder;
		
		require_once(dirname(__FILE__).'/includes/phpmailer/class.phpmailer.php');
		$this->oMailer 		= new phpmailer();	
		$this->aMailParams 	= array();
		
		if(!isset($this->sBackupMethod)) $this->sBackupMethod = 'mail';
		
		$bResult	= $this->Connect();
		if(!$bResult) return false;
	}
	
	//*********************************************************************************
	protected function SetError($sText){
		$this->aErrors[]	= $sText;
		$this->bOk			= false;
	}
	
	//*********************************************************************************
	public function GetErrors(){
		return $this->aErrors;
	}
	
	//*********************************************************************************
	public function SetMailParams($aMailParams){
		if(!is_array($aMailParams)){
			$this->SetError("Can't save mail params");
			return false;
		}else{
			$this->aMailParams = $aMailParams;
			
			$this->oMailer->Mailer 		= $aMailParams['Mailer'];
			$this->oMailer->WordWrap 	= $aMailParams['WordWrap'];
			$this->oMailer->CharSet 	= $aMailParams['CharSet'];
		
			$this->oMailer->From 		= $aMailParams['From'];
			$this->oMailer->FromName 	= $aMailParams['FromName'];
		
			//TODO: Pattern subject
			$this->oMailer->Subject 	= $aMailParams['Subject'];

		}
	}
	
	//*********************************************************************************
	protected function GetDestanationFolderPath(){
		return $this->sDestanationFolder;
	}
	
	//*********************************************************************************
	public function SendBackupFileByMail($sFullFilePath){
		if(empty($this->aMailParams)){
			$this->SetError("Unable to use mail. Params not set");
			return false;
		}
		
		$aReceivers	= $this->aMailParams['ReceiversList'];
		foreach($aReceivers as $sReceiver){
			$this->oMailer->AddAddress($sReceiver);
		}

		$this->oMailer->Body = ' ';	
		$this->oMailer->AddAttachment($sFullFilePath, basename($sFullFilePath));

		$bResult	= $this->oMailer->Send();
		if($bResult){
			return true;
		}else{
			$this->SetError($this->oMailer->ErrorInfo);
			return false;
		}

	}

	//*********************************************************************************
	// Код из стандартной поставки phpmyadmin
	// +FIX --||$type == 'timestamp'
	private function CreateSqlStatemntForSingleTable($sTableName,$dbConnection){
		$sql_statements  = "";

		$sql_statements .= "\n";
		$sql_statements .= "\n";
		$sql_statements .= "DROP TABLE IF EXISTS " . $sTableName . ";\n";

		$query = "SHOW CREATE TABLE " . $sTableName;
		$result = mysql_query($query, $dbConnection);
			if (mysql_num_rows($result) > 0) {
				$sql_create_arr = mysql_fetch_array($result);
				$sql_statements .= $sql_create_arr[1];
			}
		mysql_free_result($result);
		$sql_statements .= " ;";


		$query = "SELECT * FROM " . $sTableName;
		$result = mysql_query($query, $dbConnection);
			$fields_cnt = mysql_num_fields($result);
			$rows_cnt   = mysql_num_rows($result);

		$sql_statements .= "\n";
		$sql_statements .= "\n";

		for ($j = 0; $j < $fields_cnt; $j++) {
			$field_set[$j] = mysql_field_name($result, $j);
			$type          = mysql_field_type($result, $j);
			if ($type == 'tinyint' || $type == 'smallint' || $type == 'mediumint' || $type == 'int' ||
				$type == 'bigint' ) {
				$field_num[$j] = TRUE;
			} else {
				$field_num[$j] = FALSE;
			}
		} 

		$entries = 'INSERT INTO ' . $sTableName . ' VALUES (';
		$search			= array("\x00", "\x0a", "\x0d", "\x1a", "'"); 
		$replace		= array('\0', '\n', '\r', '\Z', "\'");
		$current_row	= 0;
		while ($row = mysql_fetch_row($result)) {
			$current_row++;
			for ($j = 0; $j < $fields_cnt; $j++) {
				if (!isset($row[$j])) {
					$values[]     = 'NULL';
				} else if ($row[$j] == '0' || $row[$j] != '') {
					if ($field_num[$j]) {
						$values[] = $row[$j];
					}
					else {
						$values[] = "'" . str_replace($search, $replace, $row[$j]) . "'";
					} 
			} else {
					$values[]     = "''";
				}
			}
			$sql_statements .= " \n" . $entries . implode(', ', $values) . ') ;';
			unset($values);
		}
		mysql_free_result($result);

		$sql_statements .= "\n";
		$sql_statements .= "\n";
		
		
		return $sql_statements;
	}
	
	//*********************************************************************************
	protected function CreateSqlStatementForDb(){
		$dbConnection = $this->dbConnection;
		
		$q = mysql_query("SHOW TABLES FROM ".$this->sDatbase, $dbConnection);
		$sSql = '
			SET AUTOCOMMIT=0;
			SET FOREIGN_KEY_CHECKS=0;';
		

		while ($row = mysql_fetch_array($q)){ 
			$sTableName = $row[0];
			$sSql = $sSql . $this->CreateSqlStatemntForSingleTable($sTableName,$dbConnection);
		}
		
		return $sSql;
	}
	
	//*********************************************************************************
	protected function CheckIsDirExists($sDestanationFolder){
		if(!is_dir($sDestanationFolder)){ 
			$bResult = @mkdir($sDestanationFolder, 0777, true);
			if(!$bResult){
				$this->SetError("Unable create backup folder");
			}
			return $bResult;
		}else return true;
	}
	
	//*********************************************************************************
	private function SaveDumpToZipFile($sSql){
		$sDestanationFolder	= $this->GetDestanationFolderPath();
		$bResult 			= $this->CheckIsDirExists($sDestanationFolder);	
		if(!$bResult) return false;
			
		//TODO: Filename pattern
		$sRawDumpFilePath 		= $sDestanationFolder . '\sql_'.date('Ymd').'_'.time().'.sql';
		$sPackedDumpFilePath 	= $sDestanationFolder . '\sql_'.date('Ymd').'_'.time().'.zip';
		
		$uRawDumpFile = fopen($sRawDumpFilePath, 'w');
		if(!@fwrite($uRawDumpFile, $sSql)){
			$this->SetError("Unable to write raw data to backup folder");
			return false;
		}
		fclose($uRawDumpFile);

		$uZip = new ZipArchive();
		if($uZip->open($sPackedDumpFilePath, ZIPARCHIVE::CREATE) !== true){
			$this->SetError("Unable to create destanation zip archive");
			return false;
		}
 
		$uZip->addFile($sRawDumpFilePath,'dump.sql');
		$uZip->close();
		
		if(!@unlink($sRawDumpFilePath)){
			$this->SetError("Unable to delete raw data");
		}
		
		return $sPackedDumpFilePath;	
	}

	//*********************************************************************************
	protected function Connect(){
		
		$dbConnection = @mysql_pconnect($this->sHost, $this->sUser, $this->sPassword);
		
		if(!$dbConnection){
			$this->SetError('Unable to connect db');
			return false;
		}
		
		if(!mysql_select_db($this->sDatbase, $dbConnection)){
			$this->SetError('Unable to select DB');
			return false;
		}
		
		if(!mysql_query ("SET NAMES 'utf8'", $dbConnection)){
			$this->SetError('Unable to set encoding');
			return false;
		}
		
		$this->dbConnection		= $dbConnection;
		return true;
	}
	
	//*********************************************************************************
	public function DeleteFile($sFullFilePath){
		
		if(!file_exists($sFullFilePath)){
			$this->SetError("Look! File $sFullFilePath already deleted");
			return false;
		}
		
		$bResult = @unlink($sFullFilePath);
		if(!$bResult){
			$this->SetError("Unable to delete $sFullFilePath");
			return false;
		}
		
		return true;
	}
	
	//*********************************************************************************
	public function PerformBackup(){
		if(!$this->bOk) return false;
	
		$sSqlBackupStatement	= $this->CreateSqlStatementForDb();
		if(!$sSqlBackupStatement) return false;
		
		$sPackedDumpFilePath	= $this->SaveDumpToZipFile($sSqlBackupStatement);
		if(!$sPackedDumpFilePath) return false;
		
		if($this->sBackupMethod == 'mail'){
			$this->SendBackupFileByMail($sPackedDumpFilePath);
			$this->DeleteFile($sPackedDumpFilePath);
		}elseif($this->sBackupMethod == 'save+mail'){
			$this->SendBackupFileByMail($sPackedDumpFilePath);
		}
	}


}
?>