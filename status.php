<?php
/** 
 * PAYONE OXID Connector is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PAYONE OXID Connector is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with PAYONE OXID Connector.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @link      http://www.payone.de
 * @copyright (C) Payone GmbH
 * @version   OXID eShop CE
 */
 
set_time_limit(0);
ini_set('memory_limit', '1024M');
ini_set('log_errors', 1);
ini_set('error_log', '../../../log/fcpoErrors.log');

if(file_exists(dirname(__FILE__)."/config.ipwhitelist.php")) {
    include_once dirname(__FILE__)."/config.ipwhitelist.php";
} else {
    echo 'Config file missing!';
    exit;
}

$sClientIp = null;
if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $aIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $sClientIp = trim($aIps[0]);
}

$sRemoteIp = isset($sClientIp) ? $sClientIp : $_SERVER['REMOTE_ADDR'];
if(array_search($sRemoteIp, $aWhitelist) === false) {
    $blMatch = false;
    foreach ($aWhitelist as $sIP) {
        if(stripos($sIP, '*') !== false) {
            $sDelimiter = '/';
            
            $sRegex = preg_quote($sIP, $sDelimiter);
            $sRegex = str_replace('\*', '\d{1,3}', $sRegex);
            $sRegex = $sDelimiter.'^'.$sRegex.'$'.$sDelimiter;

            preg_match($sRegex, $sRemoteIp, $aMatches);
            if(is_array($aMatches) && count($aMatches) == 1 && $aMatches[0] == $sRemoteIp) {
                $blMatch = true;
            }
        }
    }
    
    if($blMatch === false) {
        echo 'Access denied';
        exit;
    }
}

include_once dirname(__FILE__) . "/../../../bootstrap.php";

class fcPayOneTransactionStatusHandler extends oxBase
{

    protected $_aShopList = null;

    protected $_sLogFile = 'log/fcpo_message_forwarding.log';

    
    /**
     * Check and return post parameter
     * 
     * @param  string $sKey
     * @return string
     */
    public function fcGetPostParam( $sKey ) 
    {
        $sReturn    = '';
        $mValue     = filter_input(INPUT_GET, $sKey);
        if (!$mValue) {
            $mValue = filter_input(INPUT_POST, $sKey);
        }
        if ($mValue ) {
            if($this->getConfig()->isUtf() ) {
                $mValue = utf8_encode($mValue);
            }
            $sReturn = $mValue;
        }
        
        return $sReturn;
    }
    
    protected function _getShopList() 
    {
        if($this->_aShopList === null) {
            $aShops = array();
            
            $sQuery = "SELECT oxid FROM oxshops";
            $aRows = oxDb::getDb()->getAll($sQuery);

            foreach ($aRows as $aRow) {
                $aShops[] = $aRow[0];
            }

            $this->_aShopList = $aShops;
        }
        return $this->_aShopList;
    }
    
    protected function _getConfigParams($sParam) 
    {
        $aShops = $this->_getShopList();
        $aParams = array();
        foreach ($aShops as $sShop) {
            $mValue = $this->getConfig()->getShopConfVar($sParam, $sShop);
            if($mValue) {
                $aParams[$sShop] = $mValue;
            }
        }

        return $aParams;
    }
    
    protected function _isKeyValid() 
    {
        $sKey = $this->fcGetPostParam('key');
        if($sKey) {
            $aKeys = array_merge(
                array_values($this->_getConfigParams('sFCPOPortalKey')),
                array_values($this->_getConfigParams('sFCPOSecinvoicePortalKey')) // OXID-228: Check also SecInvoice key
            );

            foreach ($aKeys as $i => $sConfigKey) {
                if(md5($sConfigKey) == $sKey) {
                    return true;
                }
            }
        }
        return false;
    }
    
    protected function _getOrderNr() 
    {
        $oDb = oxDb::getDb();
        $sTxid = $this->fcGetPostParam('txid');

        $sQuery = "
            SELECT 
                oxordernr 
            FROM 
                oxorder 
            WHERE 
                fcpotxid = ". $oDb->quote($sTxid) ."
            LIMIT 1
        ";
        $iOrderNr = (int) $oDb->GetOne($sQuery);

        return $iOrderNr;
    }
    
    public function log() 
    {
        $iOrderNr = $this->_getOrderNr();

        $sQuery = "
            INSERT INTO fcpotransactionstatus (
                FCPO_ORDERNR,   FCPO_KEY,           FCPO_TXACTION,          FCPO_PORTALID,          FCPO_AID,           FCPO_CLEARINGTYPE,          FCPO_TXTIME,                        FCPO_CURRENCY,          FCPO_USERID,            FCPO_ACCESSNAME,            FCPO_ACCESSCODE,            FCPO_PARAM,         FCPO_MODE,          FCPO_PRICE,         FCPO_TXID,          FCPO_REFERENCE,         FCPO_SEQUENCENUMBER,            FCPO_COMPANY,           FCPO_FIRSTNAME,         FCPO_LASTNAME,          FCPO_STREET,            FCPO_ZIP,           FCPO_CITY,          FCPO_EMAIL,         FCPO_COUNTRY,           FCPO_SHIPPING_COMPANY,          FCPO_SHIPPING_FIRSTNAME,            FCPO_SHIPPING_LASTNAME,         FCPO_SHIPPING_STREET,           FCPO_SHIPPING_ZIP,          FCPO_SHIPPING_CITY,         FCPO_SHIPPING_COUNTRY,          FCPO_BANKCOUNTRY,           FCPO_BANKACCOUNT,           FCPO_BANKCODE,          FCPO_BANKACCOUNTHOLDER,         FCPO_CARDEXPIREDATE,            FCPO_CARDTYPE,          FCPO_CARDPAN,           FCPO_CUSTOMERID,            FCPO_BALANCE,           FCPO_RECEIVABLE,        FCPO_CLEARING_BANKACCOUNTHOLDER,        FCPO_CLEARING_BANKACCOUNT,          FCPO_CLEARING_BANKCODE,         FCPO_CLEARING_BANKNAME,         FCPO_CLEARING_BANKBIC,          FCPO_CLEARING_BANKIBAN,         FCPO_CLEARING_LEGALNOTE,        FCPO_CLEARING_DUEDATE,          FCPO_CLEARING_REFERENCE,        FCPO_CLEARING_INSTRUCTIONNOTE
            ) VALUES (
                '{$iOrderNr}',  '".$this->fcGetPostParam('key')."',   '".$this->fcGetPostParam('txaction')."',  '".$this->fcGetPostParam('portalid')."',  '".$this->fcGetPostParam('aid')."',   '".$this->fcGetPostParam('clearingtype')."',  FROM_UNIXTIME('".$this->fcGetPostParam('txtime')."'), '".$this->fcGetPostParam('currency')."',  '".$this->fcGetPostParam('userid')."',    '".$this->fcGetPostParam('accessname')."',    '".$this->fcGetPostParam('accesscode')."',    '".$this->fcGetPostParam('param')."', '".$this->fcGetPostParam('mode')."',  '".$this->fcGetPostParam('price')."', '".$this->fcGetPostParam('txid')."',  '".$this->fcGetPostParam('reference')."', '".$this->fcGetPostParam('sequencenumber')."',    '".$this->fcGetPostParam('company')."',   '".$this->fcGetPostParam('firstname')."', '".$this->fcGetPostParam('lastname')."',  '".$this->fcGetPostParam('street')."',    '".$this->fcGetPostParam('zip')."',   '".$this->fcGetPostParam('city')."',  '".$this->fcGetPostParam('email')."', '".$this->fcGetPostParam('country')."',   '".$this->fcGetPostParam('shipping_company')."',  '".$this->fcGetPostParam('shipping_firstname')."',    '".$this->fcGetPostParam('shipping_lastname')."', '".$this->fcGetPostParam('shipping_street')."',   '".$this->fcGetPostParam('shipping_zip')."',  '".$this->fcGetPostParam('shipping_city')."', '".$this->fcGetPostParam('shipping_country')."',  '".$this->fcGetPostParam('bankcountry')."',   '".$this->fcGetPostParam('bankaccount')."',   '".$this->fcGetPostParam('bankcode')."',  '".$this->fcGetPostParam('bankaccountholder')."', '".$this->fcGetPostParam('cardexpiredate')."',    '".$this->fcGetPostParam('cardtype')."',  '".$this->fcGetPostParam('cardpan')."',   '".$this->fcGetPostParam('customerid')."',    '".$this->fcGetPostParam('balance')."',   '".$this->fcGetPostParam('receivable')."','".$this->fcGetPostParam('clearing_bankaccountholder')."','".$this->fcGetPostParam('clearing_bankaccount')."',  '".$this->fcGetPostParam('clearing_bankcode')."', '".$this->fcGetPostParam('clearing_bankname')."', '".$this->fcGetPostParam('clearing_bankbic')."',  '".$this->fcGetPostParam('clearing_bankiban')."', '".$this->fcGetPostParam('clearing_legalnote')."','".$this->fcGetPostParam('clearing_duedate')."',  '".$this->fcGetPostParam('clearing_reference')."','".$this->fcGetPostParam('clearing_instructionnote')."'
            )";
        oxDb::getDb()->Execute($sQuery);
    }
    
    protected function _addParam($sKey, $mValue) 
    {
        $sParams = '';
        if(is_array($mValue)) {
            foreach ($mValue as $sKey2 => $mValue2) {
                $sParams .= $this->_addParam($sKey.'['.$sKey2.']', $mValue2);
            }
        } else {
            $sParams .= "&".$sKey."=".urlencode($mValue);
        }
        return $sParams;
    }

    protected function _handleForwarding() {
        $sParams = '';
        foreach($_POST as $sKey => $mValue) {
            $sParams .= $this->_addParam($sKey, $mValue);
        }

        $oConfig = $this->getConfig();
        $sShopUrl = $oConfig->getShopUrl();
        $sSslShopUrl = $oConfig->getSslShopUrl();

        $sParams = substr($sParams,1);
        $sBaseUrl = (empty($sSslShopUrl)) ? $sShopUrl : $sSslShopUrl;

        $sForwarderUrl = $sBaseUrl . 'modules/fc/fcpayone/statusforward.php';
        $this->_logForwardMessage('Forward transaction message to own controller:'.$sForwarderUrl.'...');

        $oCurl = curl_init($sForwarderUrl);
        curl_setopt($oCurl, CURLOPT_POST, 1);
        curl_setopt($oCurl, CURLOPT_POSTFIELDS, $sParams);

        curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($oCurl, CURLOPT_TIMEOUT_MS, 100);

        curl_exec($oCurl);
        $aResult = curl_getinfo($oCurl);
        $this->_logForwardMessage('Triggered forward! Result: '.print_r($aResult, true));

        curl_close($oCurl);
    }

    /**
     * Logs given message if logging is activated
     *
     * @param $sMessage
     * @return void
     */
    protected function _logForwardMessage($sMessage)
    {
        $blLoggingAllowed = $this->_fcCheckLoggingAllowed();
        if (!$blLoggingAllowed) return;

        $sBasePath = dirname(__FILE__) . "/../../../";
        $sLogFilePath = $sBasePath.$this->_sLogFile;
        $sPrefix = "[".date('Y-m-d H:i:s')."] ";
        $sFullMessage = $sPrefix.$sMessage."\n";

        $oLogFile = fopen($sLogFilePath, 'a');
        fwrite($oLogFile, $sFullMessage);
        fclose($oLogFile);
    }

    /**
     * Check if logging is activated by configuration
     *
     * @param void
     * @return bool
     */
    protected function _fcCheckLoggingAllowed()
    {
        $oConfig = $this->getConfig();
        $sLogMethod =
            $oConfig->getConfigParam('sTransactionRedirectLogging');

        $blLoggingAllowed = $sLogMethod == 'all';

        return $blLoggingAllowed;
    }

    protected function _handleMapping($oOrder) 
    {
        $sPayoneStatus = $this->fcGetPostParam('txaction');
        $sPaymentId = oxDb::getDb()->quote($oOrder->oxorder__oxpaymenttype->value);
        
        $sQuery = "SELECT fcpo_folder FROM fcpostatusmapping WHERE fcpo_payonestatus = '{$sPayoneStatus}' AND fcpo_paymentid = {$sPaymentId} ORDER BY oxid ASC LIMIT 1";
        $sFolder = oxDb::getDb()->GetOne($sQuery);
        if(!empty($sFolder)) {
            $sQuery = "UPDATE oxorder SET oxfolder = '{$sFolder}' WHERE oxid = '{$oOrder->getId()}'";
            oxDb::getDb()->Execute($sQuery);
        }
    }
    
    public function handle() 
    {
        if($this->_isKeyValid()) {
            $this->log();
            $sTxid = $this->fcGetPostParam('txid');
            $sOrderId = oxDb::getDb()->GetOne("SELECT oxid FROM oxorder WHERE fcpotxid = '".$sTxid."'");
            if($sOrderId) {
                $oOrder = oxNew('oxorder');
                $oOrder->load($sOrderId);
                if($this->_allowDebit($sTxid)) {
                    $query = "UPDATE oxorder SET oxpaid = NOW() WHERE oxid = '{$sOrderId}'";
                    oxDb::getDb()->Execute($query);
                }
                if($this->fcGetPostParam('txaction') == 'paid') {
                    $query = "UPDATE oxorder SET oxfolder = 'ORDERFOLDER_NEW', oxtransstatus = 'OK' WHERE oxid = '{$sOrderId}' AND oxtransstatus = 'INCOMPLETE' AND oxfolder = 'ORDERFOLDER_PROBLEMS'";
                    oxDb::getDb()->Execute($query);
                }

                $this->_handleMapping($oOrder);
            }
            $this->_handleForwarding();

            echo 'TSOK';
        } else {
            echo 'Key wrong or missing!';
        }
    }
    
    /**
     * Checks based on the transaction status received by PAYONE whether
     * the debit request is available for this order at the moment.
     * 
     * @param  void
     * @return bool
     */
    protected function _allowDebit($sTxid) 
    {
        $blReturn = false;
        $sAuthMode = oxDb::getDb()->GetOne("SELECT fcpoauthmode FROM oxorder WHERE fcpotxid = '".$sTxid."'");
        if ($sAuthMode == 'authorization') {
            $blReturn = true;
        } else {
            $iCount = oxDb::getDb()->GetOne("SELECT COUNT(*) FROM fcpotransactionstatus WHERE fcpo_txid = '{$sTxid}' AND fcpo_txaction = 'capture'");
            if ($iCount > 0) {
                $blReturn = true;
            }
        }
        return $blReturn;
    }
    

}

$oScript = oxNew('fcPayOneTransactionStatusHandler');
$oScript->handle();
