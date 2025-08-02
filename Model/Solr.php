<?php
namespace Aashni\MobileApi\Model;

use Aashni\MobileApi\Api\SolrInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Authorization\Model\UserContextInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Fermion\Pagelayout\Helper\SolrHelper;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Api\GuestCartItemRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer; 
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Fermion\Pagelayout\Model\Listing\ListInfo;
use Magento\Framework\Webapi\Exception as WebapiException;



class Solr implements SolrInterface
{
        // ✅ ADD THIS SINGLE PROTECTED PROPERTY
    /**
     * @var string
     */
    protected $solrUrl;
    protected $jsonSerializer;
    protected $guestCartItemRepository;
    protected $quoteIdMaskFactory;
    protected $solrHelper;
    protected $curl;
    protected $cartItemRepository;
    protected $quoteRepository;
    protected $userContext;
    protected $logger;
    protected $request;
    protected $resource;
    protected $cartManagement;
    protected $paymentMethodManagement;
    protected $addressFactory;
    protected $orderRepository;
    protected $productRepository;
    protected $storeManager;
    protected $categoryRepository;
    protected $searchCriteriaBuilder;

     /**
     * @var ListInfo
     */
    protected $listInfo;

    /**
     * Solr constructor.
     *
     * @param ListInfo $listInfo The powerful model from your Fermion module.
     * @param LoggerInterface $logger
     */

    public function __construct(
        GuestCartItemRepositoryInterface $guestCartItemRepository,
        ListInfo $listInfo,
        Curl $curl,
        JsonSerializer $jsonSerializer,
        CartItemRepositoryInterface $cartItemRepository,
        CartRepositoryInterface $quoteRepository,
        UserContextInterface $userContext,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        LoggerInterface $logger,
        RequestInterface $request,
        ResourceConnection $resource,
        CartManagementInterface $cartManagement,
        PaymentMethodManagementInterface $paymentMethodManagement,
        AddressInterfaceFactory $addressFactory,
        OrderRepositoryInterface $orderRepository,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        CategoryRepositoryInterface $categoryRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SolrHelper $solrHelper 
        
    ) { $this->solrHelper = $solrHelper; 
        $this->listInfo = $listInfo;
        $this->curl = $curl;
        $this->cartItemRepository = $cartItemRepository;
        $this->quoteRepository = $quoteRepository;
        $this->userContext = $userContext;
        $this->logger = $logger;
        $this->request = $request;
        $this->resource = $resource;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->guestCartItemRepository = $guestCartItemRepository;
        $this->cartManagement = $cartManagement;
        $this->paymentMethodManagement = $paymentMethodManagement;
        $this->addressFactory = $addressFactory;
        $this->jsonSerializer = $jsonSerializer;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->categoryRepository = $categoryRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    //Categories tab Menu names Api 
    //Postman url (https://stage.aashniandco.com/rest/V1/solr/megamenu)

    public function getMegamenuItems()
{
    $connection = $this->resource->getConnection();
    $tableName = $this->resource->getTableName('cms_block');

    // Fetch CMS block content for 'homepage_megamenu'
    $select = $connection->select()
        ->from($tableName, ['content'])
        ->where('identifier = ?', 'homepage_megamenu');

    $megamenuHtml = $connection->fetchOne($select);

    // Extract only top-level menu names
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($megamenuHtml, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $menuNames = [];

    foreach ($dom->getElementsByTagName('li') as $li) {
        if ($li->getAttribute('class') === 'tab-li') {
            $anchor = $li->getElementsByTagName('a')->item(0);
            $span = $anchor ? $anchor->getElementsByTagName('span')->item(0) : null;

            if ($span && $span->getAttribute('class') === 'menu-txt') {
                $menuText = trim($span->textContent);
                if (!empty($menuText)) {
                    $menuNames[] = $menuText;
                }
            }
        }
    }

    return ['menu_names' => $menuNames];
}


    // to get Id from Category Core Solr example
    // when user slect Bestseller front end it will give Bestseller Id 5593
   // Postman url :https://stage.aashniandco.com/rest/V1/solr/category-by-url-key/bestsellers

/**
     * ✅ 5. THIS IS THE FIXED METHOD
     * {@inheritdoc}
     */
     public function getCategoryByUrlKey(string $urlKey)
    {
        try {
            $query = 'q=cat_url_key:' . rawurlencode($urlKey) . '&rows=1';
            // Specify the fields you want
            $query .= '&fl=cat_id:cat_en_id,cat_name,cat_url_key,pare_cat_id:cat_parent_id,cat_level';

            $solrResultJson = $this->solrHelper->getCatCollection($query);
            $solrResult = json_decode($solrResultJson, true);

            if (empty($solrResult['response']['docs'])) {
                throw new NoSuchEntityException(__('No category found for URL key "%1".', $urlKey));
            }

            $rawCategoryData = $solrResult['response']['docs'][0];
            
            // ✅ FLATTEN THE RESPONSE INTO A CLEAN ASSOCIATIVE ARRAY
            $cleanCategoryData = [];
            foreach ($rawCategoryData as $key => $value) {
                // Solr often returns single values inside an array.
                // We take the first element if it's an array, otherwise we take the value itself.
                $cleanCategoryData[$key] = is_array($value) ? $value[0] : $value;
            }

            // Return the clean, flat object
            return $cleanCategoryData;

        } catch (NoSuchEntityException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Mobile API GetCategoryByUrlKey Error: ' . $e->getMessage());
            throw new WebapiException(__('An error occurred while fetching category metadata.'), 0, WebapiException::HTTP_INTERNAL_ERROR);
        }
    }



    // after getting category id from Category core above method pass  parameter from frontend and get data
    // POST : https://stage.aashniandco.com/rest/V1/solr/search    
    // Raw Json Body/{
       //   "queryParams": {
        // "query": "{!sort='cat_position_1_5593 desc, prod_en_id asc' fl='designer_name,actual_price,prod_name,prod_en_id,prod_sku,prod_small_img,prod_thumb_img,short_desc,categories-store-1_name,size_name,prod_desc,child_delivery_time,actual_price_1' rows='10' start='0'}categories-store-1_id:(5593) AND actual_price_1:{0 TO *}"
                       //   }
        // }


public function getSolrSearch($queryParams)
{
    // These lines are fine, they extract data from the incoming request.
    $query = isset($queryParams['query']) ? $queryParams['query'] : '*:*';
    $params = isset($queryParams['params']) ? $queryParams['params'] : [];
    $fl = isset($params['fl']) ? $params['fl'] : '*';
    $rows = isset($params['rows']) ? $params['rows'] : 10;

    // 1. Get the base URL from the SolrHelper class constant.
    // We use `QUERY_URL` which points to the 'aashni_dev' core.
    $baseUrl = SolrHelper::QUERY_URL;

    // 2. Build the final URL using the base URL and the query parameters.
    $solrUrl = $baseUrl . "?"
        . "q=" . urlencode($query)
        . "&fl=" . urlencode($fl)
        . "&rows=" . intval($rows)
        . "&wt=json";
        
    try {
       
        $this->curl->setOption(CURLOPT_TIMEOUT, 60);
        $this->curl->get($solrUrl);
        return json_decode($this->curl->getBody(), true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

   // after getting category id from Category core above method pass  parameter from frontend and get data
    // POST : https://stage.aashniandco.com/rest/V1/solr/search    
    // Raw Json Body/{
       //   "queryParams": {
        // "query": "{!sort='cat_position_1_5593 desc, prod_en_id asc' fl='designer_name,actual_price,prod_name,prod_en_id,prod_sku,prod_small_img,prod_thumb_img,short_desc,categories-store-1_name,size_name,prod_desc,child_delivery_time,actual_price_1' rows='10' start='0'}categories-store-1_id:(5593) AND actual_price_1:{0 TO *}"
                       //   }
        // }


/// https://stage.aashniandco.com/rest/V1/solr/child-categories/Men

public function getChildCategories(string $parentCategoryName)
    {
  

        $solrBaseUrl = SolrHelper::SEARCH_QUERY_URL;

        // We use a facet query to get the unique values from the 'cat_name' field.
        // - 'q' filters products where parent_cat_name matches.
        // - 'facet.field' specifies which field to get the unique values from.
        // - 'rows=0' makes the query very fast as we don't need the product data itself.
        $queryParams = [
            'q' => 'parent_cat_name:"' . $parentCategoryName . '"',
            'facet' => 'true',
            'facet.field' => 'cat_name',
            'facet.mincount' => '1', // Only include categories that exist on at least one product
            'rows' => '0',
            'wt' => 'json' // We want the response in JSON format
        ];

        $solrUrl = $solrBaseUrl . '?' . http_build_query($queryParams);

        try {
            $this->curl->get($solrUrl);
            $responseBody = $this->curl->getBody();
            $data = json_decode($responseBody, true);

            // Check if the expected facet data exists in the Solr response
            if (isset($data['facet_counts']['facet_fields']['cat_name'])) {
                $facetData = $data['facet_counts']['facet_fields']['cat_name'];
                $childCategories = [];

                // The facet data is a flat array like ["Category A", 10, "Category B", 5]
                // We loop through it, skipping the counts, to extract only the names.
                for ($i = 0; $i < count($facetData); $i += 2) {
                    $childCategories[] = $facetData[$i];
                }

                return [
                    'success' => true,
                    'parent_category' => $parentCategoryName,
                    'child_categories' => $childCategories
                ];
            } else {
                // Return success with an empty array if no child categories are found
                return [
                    'success' => true,
                    'parent_category' => $parentCategoryName,
                    'child_categories' => []
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Solr Child Category Fetch Error for "' . $parentCategoryName . '": ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to fetch child categories from Solr.'
            ];
        }
    }




// Designers List Api
// Postman url for below method (https://stage.aashniandco.com/rest/V1/solr/designers)


   public function getDesigners()
{
    $queryParams = [
        'q' => 'designer_name:[* TO *]',
        'fq' => 'categories-store-1_url_path:designers',
        'facet' => 'true',
        'facet.field' => 'designer_name',
        'facet.limit' => 500,
        'rows' => 0,
    ];

    $solrUrl = SolrHelper::QUERY_URL . '?' . http_build_query($queryParams);
    

    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 30);
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

    // When user select designer name from list it will pass to below method 
   public function getDesignerData(string $designerName)
{
    // Safely quote the designer name for Solr filter query
    $escapedDesignerName = '"' . addcslashes($designerName, '"') . '"';

    // Define Solr query parameters
    $queryParams = [
        'q' => 'designer_name:' . $escapedDesignerName, // Only fetch products with this designer
        'fl' => 'designer_name,prod_small_img,prod_thumb_img,short_desc,prod_desc,size_name,prod_sku,actual_price_1',
        'rows' => 10,
        'wt' => 'json'
    ];
     $solrUrl = SolrHelper::QUERY_URL . '?' . http_build_query($queryParams);
  
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60);
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

    //--- GUEST USER ---
// Guest Cart Item Update API
// POST : https://stage.aashniandco.com/rest/V1/solr/guest-cart/item/updateQty
//Raw Body JSON{
//   "cart_id": "8yz5ArW47fUiZsS5xnEKcrQJ8HSpNoTI",  
//   "item_id": 718605,                              
//   "qty": 5                                      
// }

//
/**
 * {@inheritdoc}
 * This is the new function for GUEST users, modeled after your reference.
 */
public function updateGuestCartItemQty()
{
    // --- START: MODIFIED CODE ---
    try {
        // 1. Get the raw JSON content from the request body
        $requestBody = $this->request->getContent();

        // 2. Decode the JSON string into a PHP array
        $data = $this->jsonSerializer->unserialize($requestBody);

        // 3. Get parameters from the decoded array
        $maskedCartId = $data['cart_id'] ?? null;
        $itemId = $data['item_id'] ?? null;
        $qty = $data['qty'] ?? null;

    } catch (\InvalidArgumentException $e) {
        // This catches errors from malformed JSON
        $this->logger->error("Guest Cart Update Error: Invalid JSON provided. " . $e->getMessage());
        return [false, "Invalid JSON format in request body."];
    }

    if (!$maskedCartId || !$itemId || !$qty) {
       
        return [false, "Missing cart_id, item_id, or qty in the JSON body."];
    }

    try {
  
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($maskedCartId, 'masked_id');
        $realQuoteId = $quoteIdMask->getQuoteId();

        if (!$realQuoteId) {
            return [false, "Guest cart with ID $maskedCartId not found"];
        
        }


        $quote = $this->quoteRepository->get($realQuoteId);
        $item = $quote->getItemById($itemId);

        if (!$item) {
            return [false, "Item ID $itemId not found in cart"];
        }

        $qty = (int)$qty;
        if ($qty < 1) {
            $qty = 1;
        }

        $item->setQty($qty);
        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        $rowTotal = $item->getRowTotal();
        $subtotal = $quote->getSubtotal();

        return [
            true,
            "Quantity updated successfully",
            [
                'qty' => $qty,
                'row_total' => $rowTotal,
                'subtotal' => $subtotal,
            ]
        ];
    } catch (\Exception $e) {
        $this->logger->error("Guest Cart Update Error: " . $e->getMessage());
        return [false, 'Error: ' . $e->getMessage()];
    }
}


 //  Guest Cart Items Delete API
  // https://stage.aashniandco.com/rest/V1/guest-carts/delete-item
  // Raw Json Body
//   {
//   "cartId": "y74aqM9YZ5ABNvnYMUCSdtqQZ6LB6Wox",   
//   "itemId": 718609                            
// }

   /**
     * {@inheritdoc}
     * This implementation is modeled after the deleteCartItem pattern.
     */
    public function deleteGuestItem($cartId, $itemId)
    {
        // 1. Validate the input, just like the reference method
        if (empty($cartId) || empty($itemId)) {
            throw new InputException(__('Parameters "cartId" and "itemId" are required.'));
        }

        try {
            // 2. The core logic: Use the dedicated repository.
            // This repository is designed to handle the masked guest cart ID ($cartId) directly.
            // There is NO NEED to manually look up the real quote ID.
            $success = $this->guestCartItemRepository->deleteById($cartId, $itemId);

        } catch (NoSuchEntityException $e) {
            // If the repository can't find the cart or item, it throws this exception.
            // We re-throw it to give a clear error message to the API consumer.
            throw new NoSuchEntityException(__('The requested cart or item doesn\'t exist. Please check the IDs.'), $e);

        } catch (\Exception $e) {
            // Catch any other unexpected errors during the deletion process.
            $this->logger->error('Guest cart item deletion failed: ' . $e->getMessage());
            throw new CouldNotSaveException(__('The item could not be removed from the cart at this time.'), $e);
        }

        // 3. Return the result. The deleteById method returns a boolean.
        return $success;
    }


    /**
     * ✅ ADD THIS PRIVATE HELPER METHOD
     *
     * Helper to clean up Solr documents, as Solr often returns single values in an array.
     * @param array $doc The raw document from Solr.
     * @return array The cleaned document.
     */
    private function cleanSolrDoc(array $doc): array
    {
        $cleanDoc = [];
        foreach ($doc as $key => $value) {
            $cleanDoc[$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
        }
        return $cleanDoc;
    }

    // from front end user will select categorires and that category id filter will show 

   //  Example:  https://stage.aashniandco.com/rest/V1/solr/category/1374/filters

        /**
     * Retrieves available filters, selectively correcting 'sizes' and 'delivery_times'.
     *
     * @param int $categoryId
     * @return array
     * @throws NoSuchEntityException
     * @throws WebapiException
     */
    public function getFilters(int $categoryId)
    {
        try {
            // 1. Get Category Data from Solr to ensure it exists.
            $categoryData = $this->listInfo->getCategoryFilteredDataSolr($categoryId);
            if (empty($categoryData)) {
                throw new NoSuchEntityException(__('Category with ID "%1" does not exist.', $categoryId));
            }

            // 2. We only need the filters, so we can ask Solr for 0 products.
            $start = 0;
            $rows = 0; // Ask for zero product documents
            $appliedFilters = ["is_scroll_req" => 0];

            $solrResult = $this->listInfo->getFilteredData($start, $rows, $categoryData, $appliedFilters);

            if (!$solrResult || !isset($solrResult['facet_counts']['facet_fields'])) {
                 throw new \Exception('Failed to retrieve filter data from Solr.');
            }

            // 3. Process the facets using the original method.
            // This will give us the mostly-correct data, but with bad IDs for some filters.
            $rawFacets = $solrResult['facet_counts']['facet_fields'] ?? [];
            $processedFacets = $this->listInfo->filterResponseFacets(
                $rawFacets,
                1, // on_load flag
                null, // no applied price range
                $categoryData['pare_cat_id'] ?? '',
                $categoryData['cat_id'] ?? '',
                $categoryData['cat_level'] ?? 0,
                $categoryData['cat_path'] ?? ''
            );

            // 4. Now, we selectively override the incorrect filters using the raw Solr data.
            $filtersToCorrect = [
                'size_token'          => 'sizes',
                'delivery_time_token' => 'delivery_times'
                // Add other token-based filters here if they also need correction
            ];

            foreach ($filtersToCorrect as $solrField => $apiKey) {
                // Check if the raw Solr data for this token exists
                if (isset($rawFacets[$solrField]) && !empty($rawFacets[$solrField])) {
                    $correctedOptions = [];
                    $facetData = $rawFacets[$solrField];
                    
                    // Loop through the raw facet data ['value1', count1, 'value2', count2, ...]
                    for ($i = 0; $i < count($facetData); $i += 2) {
                        $token = $facetData[$i]; // e.g., "5434|Small"
                        $count = $facetData[$i + 1];

                        if ($count == 0) continue; // Skip items with no products

                        $parts = explode('|', $token, 2);
                        if (count($parts) === 2) {
                            // The key is the correct ID, the value is the name
                            $correctedOptions[$parts[0]] = $parts[1]; 
                        }
                    }

                    // If we successfully built a list of corrected options,
                    // overwrite the incorrect data in our main $processedFacets array.
                    if (!empty($correctedOptions)) {
                        $processedFacets[$apiKey] = $correctedOptions;
                    }
                }
            }
           
            // 5. Reformat the final, corrected data into the desired array of objects.
            $finalResponse = [];
            foreach ($processedFacets as $key => $value) {
                // The key is the filter type (e.g., 'categories', 'sizes')
                // The value is the map of options (e.g., {"5434": "Small", ...})
                $finalResponse[] = [$key => $value];
            }
            
            return $finalResponse;

        } catch (NoSuchEntityException $e) {
            // Re-throw specific exceptions for correct HTTP status codes
            throw new NoSuchEntityException(__($e->getMessage()), $e, $e->getCode());
        } catch (\Exception $e) {
            $this->logger->error('Mobile API GetFilters Error: ' . $e->getMessage());
            // Throw a generic 500 error for other issues
            throw new WebapiException(__('An error occurred while fetching filter data.'), 0, WebapiException::HTTP_INTERNAL_ERROR);
        }
    }


    // From Mobile app user  Shipping infromation from 
    // 
    // will be submitted then during payment below method will be called 

   //https://stage.aashniandco.com/rest/V1/aashni/place-order


public function placeOrderForCustomer(string $paymentMethodCode, $billingAddress, string $paymentMethodNonce): int
{
    $customerId = $this->userContext->getUserId();
    if (!$customerId) {
        throw new \Magento\Framework\Exception\CouldNotSaveException(__('Customer session not found.'));
    }

    if (!is_array($billingAddress)) {
         $this->logger->critical('PlaceOrder API Error: billingAddress was not an array.', ['data_received' => gettype($billingAddress)]);
         throw new \Magento\Framework\Exception\LocalizedException(__('Billing address data is invalid.'));
    }

    try {

        $cartId = $this->cartManagement->getCartForCustomer($customerId)->getId();
        $quote = $this->quoteRepository->get($cartId);
        $address = $this->addressFactory->create();
        if (isset($billingAddress['street']) && is_array($billingAddress['street'])) {
            $address->setStreet($billingAddress['street']);
            unset($billingAddress['street']);
        }
        $address->setData($billingAddress);
        $quote->setBillingAddress($address);
        
        $payment = $quote->getPayment();
        $payment->setMethod($paymentMethodCode);

        // ✅ THIS IS THE FINAL, CORRECT LINE BASED ON THE GREP RESULTS.
        // The key the module is looking for is 'token'.
        $payment->setAdditionalInformation('token', $paymentMethodNonce);

        $this->quoteRepository->save($quote);
        $orderId = $this->cartManagement->placeOrder($quote->getId(), $payment);
        return $orderId;

    } catch (\Exception $e) {
        $this->logger->critical('PlaceOrder API Error: ' . $e->getMessage(), ['exception' => $e]);
        throw new \Magento\Framework\Exception\CouldNotSaveException(__('Could not place order: %1', $e->getMessage()));
    }
}

///https://stage.aashniandco.com/rest/V1/aashni/order-details/:orderId

//  Oreder Details API
public function getOrderDetails(int $orderId): array
    {
        $customerId = $this->userContext->getUserId();
        if (!$customerId) {
            throw new AuthorizationException(__('The customer is not authorized. Please log in.'));
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(__('Order with ID "%1" does not exist.', $orderId));
        }


        if ($order->getCustomerId() != $customerId) {
            throw new AuthorizationException(__('You are not authorized to view this order.'));
        }

        // Build the structured response array
        return [
            'order_id' => $order->getIncrementId(),
            'order_date' => $order->getCreatedAt(),
            'status' => $order->getStatusLabel(),
            'shipping_address' => $this->_formatAddress($order->getShippingAddress()),
            'billing_address' => $this->_formatAddress($order->getBillingAddress()),
            'shipping_method' => $order->getShippingDescription(),
            'payment_method' => $this->_getPaymentInfo($order->getPayment()),
            'items' => $this->_getOrderItems($order->getAllVisibleItems()),
            'totals' => [
                'subtotal' => (float)$order->getSubtotal(),
                'shipping' => (float)$order->getShippingAmount(),
                'grand_total' => (float)$order->getGrandTotal()
            ]
        ];
    }
    
    /**
     * Helper to format an address object into an array.
     *
     * @param OrderAddressInterface|null $address
     * @return array|null
     */
    private function _formatAddress(?OrderAddressInterface $address): ?array
    {
        if (!$address) {
            return null;
        }
        return [
            'name' => $address->getFirstname() . ' ' . $address->getLastname(),
            'street' => implode(', ', $address->getStreet()),
            'city' => $address->getCity(),
            'postcode' => $address->getPostcode(),
            'country' => $address->getCountryId(),
            'telephone' => 'T: ' . $address->getTelephone()
        ];
    }
    
    /**
     * Helper to get payment information.
     *
     * @param Payment $payment
     * @return array
     */

    private function _getPaymentInfo(Payment $payment): array
{
    $details = 'N/A';
    $additionalInfo = $payment->getAdditionalInformation();

    // First try standard cc fields
    if ($payment->getCcLast4()) {
        $details = ($payment->getCcType() ?: 'Card') . ' ending **** ' . $payment->getCcLast4();
    }

    // If those are missing, parse Stripe's source_info field
    if (isset($additionalInfo['source_info'])) {
        $sourceInfo = json_decode($additionalInfo['source_info'], true);
        if (is_array($sourceInfo)) {
            $card = $sourceInfo['Card'] ?? null;
            $expires = $sourceInfo['Expires'] ?? null;

            if ($card && $expires) {
                $details = "$card\nExpires $expires";
            } elseif ($card) {
                $details = $card;
            }
        }
    }

    return [
        'title' => $payment->getMethodInstance()->getTitle(),
        'details' => $details
    ];
}

    /**
     * Helper to format order items.
     *
     * @param OrderItemInterface[] $items
     * @return array
     */
    private function _getOrderItems(array $items): array
    {
        $itemsData = [];
        try {
            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        } catch (NoSuchEntityException $e) {
            $mediaUrl = '';
        }

        foreach ($items as $item) {
            try {
                $product = $this->productRepository->getById($item->getProductId());
                $imageUrl = $product->getImage() ? $mediaUrl . 'catalog/product' . $product->getImage() : null;
            } catch (NoSuchEntityException $e) {
                $imageUrl = null;
            }

            $itemsData[] = [
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'qty' => (int)$item->getQtyOrdered(),
                'price' => (float)$item->getPrice(),
                'subtotal' => (float)$item->getRowTotal(),
                'options' => $this->_getFormattedOptions($item),
                'image_url' => $imageUrl
            ];
        }
        return $itemsData;
    }

    /**
     * Helper to get formatted product options.
     *
     * @param OrderItemInterface $item
     * @return string
     */
    private function _getFormattedOptions(OrderItemInterface $item): string
    {
        $optionsArr = [];
        $options = $item->getProductOptions();
        
        if (isset($options['attributes_info'])) {
            foreach ($options['attributes_info'] as $option) {
                $optionsArr[] = $option['label'] . ': ' . $option['value'];
            }
        }
        return implode(', ', $optionsArr);
    }

////////////// get Order details end //////


// ** this method is use to get cart_id with cart details
public function getCartDetailsByCustomerId($customerId)

{
    $this->logger->info("getCartDetailsByCustomerId called with customer_id: " . $customerId);

    try {
        $connection = $this->resource->getConnection();
        $quoteTable = $this->resource->getTableName('quote');
        $customerTable = $this->resource->getTableName('customer_entity');
        $addressTable = $this->resource->getTableName('quote_address');
        $itemTable = $this->resource->getTableName('quote_item');

        $sql = "
            SELECT
                q.entity_id AS quote_id,
                q.is_active,
                ce.email AS customer_email,
                qa.weight AS total_cart_weight,
                qi.item_id,
                qi.product_id,
                qi.sku,
                qi.name AS product_name,
                qi.qty AS item_qty,
                qi.price AS item_original_price,
                qi.row_total AS item_row_total_after_discounts,
                qi.weight AS individual_item_weight,
                (qi.weight * qi.qty) AS item_row_weight
            FROM
                {$quoteTable} AS q
            JOIN
                {$customerTable} AS ce ON q.customer_id = ce.entity_id
            LEFT JOIN
                {$addressTable} AS qa ON q.entity_id = qa.quote_id AND qa.address_type = 'shipping'
            JOIN
                {$itemTable} AS qi ON q.entity_id = qi.quote_id
            WHERE
                q.customer_id = :customer_id
                AND q.is_active = 1
                AND qi.parent_item_id IS NULL
        ";

        $bind = ['customer_id' => (int)$customerId];
        $result = $connection->fetchAll($sql, $bind);

        return $result;
    } catch (\Exception $e) {
        $this->logger->error('Error in getCartDetailsByCustomerId: ' . $e->getMessage());
        throw new \Magento\Framework\Exception\LocalizedException(__('Unable to fetch cart details.'));
    }
}




public function getAllCountryCodes()
{
    try {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('directory_country');

        $sql = "SELECT country_id AS country_code FROM $table";
        $results = $connection->fetchAll($sql);

        return [
            'success' => true,
            'countries' => $results
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

 // cart Qty delete for Loggedin User
// https://stage.aashniandco.com/rest/V1/solr/cart/item/delete
//   //Raw Body Json
// {
//   "item_id": 718667
// }


    public function deleteCartItem()

    {
         $body = json_decode($this->request->getContent(), true);
       $itemId = $body['item_id'] ?? null;


    if (!$itemId) {
        return ['success' => false, 'message' => 'Missing item_id'];
    }

    try {
        $customerId = $this->userContext->getUserId();
        if (!$customerId) {
            return ['success' => false, 'message' => 'User not authorized or not logged in'];
        }

        $quote = $this->quoteRepository->getActiveForCustomer($customerId);
        $item = $quote->getItemById($itemId);

        if (!$item) {
            return ['success' => false, 'message' => "Item ID $itemId not found in cart"];
        }

        $quote->removeItem($itemId)->collectTotals();
        $this->quoteRepository->save($quote);

        return ['success' => true, 'message' => "Item ID $itemId deleted successfully from cart"];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    }

       // Update cart Qty For Loggedin User
      //https://stage.aashniandco.com/rest/V1/solr/cart/item/updateQty
      //Raw Body Json
     //       {
     //   "item_id": 718666,
     //   "qty": 3
       // }


      public function updateCartItemQty()
    {
        $body = json_decode($this->request->getContent(), true);
       $itemId = $body['item_id'] ?? null;
       $qty = $body['qty'] ?? null;

        if (!$itemId || !$qty) {
            return [false, "Missing item_id or qty"];
        }

        try {
            $customerId = $this->userContext->getUserId();
            if (!$customerId) {
                return [false, "User not authorized or not logged in"];
            }

            $quote = $this->quoteRepository->getActiveForCustomer($customerId);
            $item = $quote->getItemById($itemId);

            if (!$item) {
                return [false, "Item ID $itemId not found in cart"];
            }

            $qty = (int)$qty;
            if ($qty < 1) {
                $qty = 1;
            }

            $item->setQty($qty);
            $quote->collectTotals();
            $this->quoteRepository->save($quote);

            $rowTotal = $item->getRowTotal();
            $subtotal = $quote->getSubtotal();

            return [
                true,
                "Quantity updated successfully",
                'qty' => $qty,
                'row_total' => $rowTotal,
                'subtotal' => $subtotal,
            ];
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return [false, 'Error: ' . $e->getMessage()];
        }
    } 

}
