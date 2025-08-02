<?php
namespace Aashni\MobileApi\Api;

interface SolrInterface
{
    /**
     * Get Solr Data
     *
     * @return array
     */
    public function getSolrData();
    
    /**
     * Fetches designer products from Solr based on designer name.
     *
     * @param string $designerName Designer name to filter products.
     * @return array JSON response with designer products.
     */
    public function getDesignerData(string $designerName);


   /**
     * Get Solr NewIn Data
     *
     * @return array
     */
    public function getNewInData();



   /**
     * Get Solr NewIn-Accessories data
     *
     * @return array
     */
    public function getNewInAccessories();


}

