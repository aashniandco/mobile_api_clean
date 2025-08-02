<?php
namespace Aashni\MobileApi\Model;

use Aashni\MobileApi\Api\SolrInterface;
use Magento\Framework\HTTP\Client\Curl;

class Solr implements SolrInterface
{
    protected $curl;

    public function __construct(Curl $curl)
    {
        $this->curl = $curl;
    }

    public function getSolrData()
    {
        $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?q=*:*&fq=categories-store-1_url_path:%22designers%22&facet=true&facet.field=designer_name&facet.limit=-1";

        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
            $this->curl->get($solrUrl);
            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }


    public function getDesignerData(string $designerName)
    {
               $encodedDesignerName = urlencode($designerName);
               $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?q=*:*&fq=designer_name:%22{$encodedDesignerName}%22&fl=designer_name,prod_small_img,prod_thumb_img,short_desc,prod_desc,size_name,prod_sku,actual_price_1&rows=400&wt=json";
               
    
        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, 60);
            $this->curl->get($solrUrl);
            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
        echo "getDesignerData called>>";
    }
    

    // New In Womens-clothing


 public function getNewInData()
    {
        $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(%20%20%20%20%20categories-store-1_id%3A(1372%201500%202295%202296%202297%202298%202299%202665%202666%202668%202670%202671%202672%202673%202676%202677%202681%202683%202732%202809%203038%203040%203049%203063%203067%203069%203091%203103%203105%203107%203109%203111%203121%203208%203210%203213%203215%203217%203258%203260%203303%203341%203364%203366%203765%205559%204046%204352%204353%204354%204460%204463%204476%205594)%20%20%20%20%20AND%20%20%20%20%20%20categories-store-1_name%3A(%20%20%20%20%20%20%20%20%20%22classic%20lehengas%22%20%22draped%20lehengas%22%20%22contemporary%20lehengas%22%20%22ruffle%20lehengas%22%20%22lighter%20lehengas%22%20%20%20%20%20%20%20%20%20%20%22printed%20lehengas%22%20%22floral%20lehengas%22%20%22festive%20lehengas%22%20%22bridal%20lehengas%22%20%22handwoven%20lehengas%22%20%20%20%20%20%20%20%20%20%22anarkalis%22%20%22sharara%20sets%22%20%22palazzo%20sets%22%20%22printed%20kurtas%22%20%22straight%20kurta%20sets%22%20%22dhoti%20kurtas%22%20%22kurtas%22%20%20%20%20%20%20%20%20%20%22handwoven%20kurta%20sets%22%20%22kurta%20sets%22%20%22classic%20sarees%22%20%22pre%20draped%20sarees%22%20%22saree%20gowns%22%20%22printed%20sarees%22%20%20%20%20%20%20%20%20%20%22pants%20and%20dhoti%20sarees%22%20%22handwoven%20sarees%22%20%22ruffle%20sarees%22%20%22striped%20sarees%22%20%22lehenga%20sarees%22%20%20%20%20%20%20%20%20%20%22maxi%20dresses%22%20%22midi%20dresses%22%20%22mini%20dresses%22%20%22silk%20dresses%22%20%22evening%20dresses%22%20%22day%20dresses%22%20%22floral%20dresses%22%20%20%20%20%20%20%20%20%20%22knee%20length%20dresses%22%20%22shift%20dresses%22%20%22cropped%22%20%22blouses%22%20%22shirts%22%20%22classic%20tops%22%20%22t%20shirts%22%20%22off%20the%20shoulder%22%20%20%20%20%20%20%20%20%20%22printed%22%20%22sweatshirts%22%20%22hoodies%22%20%22suits%22%20%22tracksuits%22%20%22jacket%20sets%22%20%22crop%20top%20sets%22%20%22skirt%20sets%22%20%22pant%20sets%22%20%20%20%20%20%20%20%20%20%22short%20sets%22%20%22lehenga%20skirts%22%20%22midi%22%20%22mini%22%20%22knee%20length%22%20%22embellished%22%20%22chudidars%22%20%22dhotis%22%20%22palazzos%22%20%20%20%20%20%20%20%20%20%22straight%20pants%22%20%22draped%20pants%22%20%22trousers%22%20%22feather%22%20%22tulle%22%20%22cape%22%20%22traditional%22%20%22embroidered%22%20%22cocktail%22%20%20%20%20%20%20%20%20%20%22tunic%20sets%22%20%22short%22%20%22long%22%20%22printed%22%20%22plain%22%20%22embellished%22%20%22dhoti%20kurtas%22%20%22printed%20kurtas%22%20%22contemporary%20kurtas%22%20%20%20%20%20%20%20%20%20%22plain%20kurtas%22%20%22palazzo%20sets%22%20%20%20%20%20)%20%20%20%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20)&q=*%3A*&rows=80000";

        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
            $this->curl->get($solrUrl);
            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }



       // New In Accessories


 public function getNewInAccessories()
 {
     $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(%20%20%20%20%20categories-store-1_id%3A(%20%20%20%20%20%20%20%20%201372%201501%202304%202305%202306%202613%202700%202701%202702%202703%202716%202717%202718%202721%202722%20%20%20%20%20%20%20%20%20%202723%202725%202726%202728%203699%203792%203902%205609%205570%205572%20%20%20%20%20)%20%20%20%20%20%20AND%20categories-store-1_name%3A(%20%20%20%20%20%20%20%20%20%22stoles%22%20%22dupattas%22%20%22shawls%22%20%22scarves%22%20%20%20%20%20%20%20%20%20%20%22clutch%20bags%22%20%22backpacks%22%20%22potlis%22%20%22tote%20bags%22%20%22trunks%22%20%22bangle%20box%22%20%22laptop%20bags%22%20%22wallets%22%20%20%20%20%20%20%20%20%20%22belts%22%20%22masks%22%20%20%20%20%20%20%20%20%20%22sandals%22%20%22wedges%22%20%22juttis%22%20%22heels%22%20%22sneakers%22%20%22kolhapuris%22%20%22mules%22%20%20%20%20%20)%20%20%20%20%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20)&q=*%3A*&rows=8000";
     try {
         $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
         $this->curl->get($solrUrl);
         $response = $this->curl->getBody();
         return json_decode($response, true);
     } catch (\Exception $e) {
         return ['error' => $e->getMessage()];
     }
 }


}
