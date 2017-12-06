<?php
namespace Swoolefy\Core;

trait ModelTrait {
	/**
	 * isGet
	 * @return boolean
	 */
	public function isGet() {
		return ($this->request->server['REQUEST_METHOD'] == 'GET') ? true :false;
	}

	/**
	 * isPost
	 * @return boolean
	 */
	public function isPost() {
		return ($this->request->server['REQUEST_METHOD'] == 'POST') ? true :false;
	}

	/**
	 * isPut
	 * @return boolean
	 */
	public function isPut() {
		return ($this->request->server['REQUEST_METHOD'] == 'PUT') ? true :false;
	}

	/**
	 * isDelete
	 * @return boolean
	 */
	public function isDelete() {
		return ($this->request->server['REQUEST_METHOD'] == 'DELETE') ? true :false;
	}

	/**
	 * isAjax
	 * @return boolean
	 */
	public function isAjax() {
		return (isset($this->request->header['x-requested-with']) && strtolower($this->request->header['x-requested-with']) == 'xmlhttprequest') ? true : false;
	}

	/**
	 * isSsl
	 * @return   boolean
	 */
	public function isSsl() {
	    if(isset($this->request->server['HTTPS']) && ('1' == $this->request->server['HTTPS'] || 'on' == strtolower($this->request->server['HTTPS']))){
	        return true;
	    }elseif(isset($this->request->server['SERVER_PORT']) && ('443' == $this->request->server['SERVER_PORT'] )) {
	        return true;
	    }
	    return false;
	}

	/**
	 * isMobile 
	 * @return   boolean
	 */
    public function isMobile() {
        if (isset($this->request->server['HTTP_VIA']) && stristr($this->request->server['HTTP_VIA'], "wap")) {
            return true;
        
        }elseif(isset($this->request->server['HTTP_ACCEPT']) && strpos(strtoupper($this->request->server['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        
        }elseif(isset($this->request->server['HTTP_X_WAP_PROFILE']) || isset($this->request->server['HTTP_PROFILE'])) {
            return true;
        
        }elseif(isset($this->request->server['HTTP_USER_AGENT']) && preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', $this->request->server['HTTP_USER_AGENT'])) {
            return true;
        
        }else {
            return false;
        }
    }
}