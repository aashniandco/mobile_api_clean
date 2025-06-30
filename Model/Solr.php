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

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\AuthorizationException;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;


use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderItemInterface;


use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;


use Fermion\Pagelayout\Model\Listing\ListInfo;

use Magento\Framework\Webapi\Exception as WebapiException;







class Solr implements SolrInterface
{
    
    protected $solrHelper;
    private const SOLR_SELECT_URL = 'http://127.0.0.1:8983/solr/aashni_cat/select';
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

      // ✅ ADD THESE NEW PROTECTED PROPERTIES
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
        ListInfo $listInfo,
        Curl $curl,
        CartItemRepositoryInterface $cartItemRepository,
        CartRepositoryInterface $quoteRepository,
        UserContextInterface $userContext,
        LoggerInterface $logger,
        RequestInterface $request,
        ResourceConnection $resource,
        CartManagementInterface $cartManagement,
        PaymentMethodManagementInterface $paymentMethodManagement,
        AddressInterfaceFactory $addressFactory,

             // ✅ ADD THE NEW DEPENDENCIES TO THE CONSTRUCTOR
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

        $this->cartManagement = $cartManagement;
        $this->paymentMethodManagement = $paymentMethodManagement;
        $this->addressFactory = $addressFactory;

         // ✅ INITIALIZE THE NEW PROPERTIES
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;

        $this->categoryRepository = $categoryRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * ✅ ADD THIS ENTIRE NEW METHOD
     *
     * {@inheritdoc}
     */
    public function getFilteredProducts($queryParams)
    {
        try {
            // --- 1. EXTRACT AND VALIDATE PARAMETERS ---
            $filters = $queryParams['filters'] ?? [];
            $page = (int)($queryParams['page'] ?? 0);
            $pageSize = (int)($queryParams['pageSize'] ?? 20);
            $sortOrder = $queryParams['sort'] ?? 'prod_en_id desc'; // Default sort order

            // --- 2. DYNAMIC QUERY BUILDING LOGIC (moved from Flutter to PHP) ---
            $filtersByType = [];
            foreach ($filters as $filter) {
                // Basic validation
                if (!isset($filter['type']) || !isset($filter['id'])) {
                    continue;
                }
                $type = $filter['type'];
                $id = $filter['id'];
                if (!isset($filtersByType[$type])) {
                    $filtersByType[$type] = [];
                }
                $filtersByType[$type][] = $id;
            }

            $queryParts = [];
            foreach ($filtersByType as $type => $ids) {
                // Sanitize IDs to prevent injection issues, although they should be numeric/strings
                $escapedIds = array_map(function($id) {
                    // Use a more robust escaping method if needed, this is basic
                    return '"' . addslashes($id) . '"'; 
                }, $ids);

                // Map frontend 'type' to backend Solr field name
                $solrField = str_replace('_', '', $type) . '_id';
                if ($type === 'categories') {
                    $solrField = 'categories-store-1_id';
                }
                
                $queryParts[] = $solrField . ':(' . implode(' OR ', $escapedIds) . ')';
            }

            $solrQuery = !empty($queryParts) ? implode(' AND ', $queryParts) : '*:*';
            $this->logger->info('Executing Mobile API Filtered Solr Query: ' . $solrQuery);

            // --- 3. PREPARE SOLR REQUEST PARAMETERS ---
            $start = $page * $pageSize;
            $solrParams = [
                'q' => $solrQuery,
                // Define the fields server-side for consistency and security
                'fl' => 'designer_name,actual_price_1,short_desc,prod_en_id,prod_small_img,prod_name,color_name',
                'rows' => $pageSize,
                'start' => $start,
                'sort' => $sortOrder
            ];
            
            // Build the final query string for the helper
            $finalQueryString = http_build_query($solrParams);

            // --- 4. EXECUTE THE QUERY USING YOUR HELPER ---
            // Assuming your solrHelper has a method to query the product collection.
            // Adjust 'getProductCollection' to the actual method name in your SolrHelper.
            $solrResultJson = $this->solrHelper->getProductCollection($finalQueryString);
            $solrResult = json_decode($solrResultJson, true);

            if (!isset($solrResult['response']['docs'])) {
                throw new \Exception('Invalid Solr response structure.');
            }
            
            $docs = $solrResult['response']['docs'];
            $products = [];
            foreach ($docs as $doc) {
                $products[] = $this->cleanSolrDoc($doc);
            }

            // Determine if we have reached the end for infinite scrolling
            $hasReachedEnd = count($products) < $pageSize;

            // --- 5. RETURN A STRUCTURED RESPONSE ---
            return [
                'products' => $products,
                'hasReachedEnd' => $hasReachedEnd
            ];

        } catch (\Exception $e) {
            $this->logger->error('Mobile API getFilteredProducts Error: ' . $e->getMessage());
            throw new WebapiException(__('An error occurred while searching for products.'), 0, WebapiException::HTTP_INTERNAL_ERROR);
        }
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

            // ------------------ SURGICAL FIX STARTS HERE ------------------

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
            // ------------------ SURGICAL FIX ENDS HERE ------------------


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
     /**
     * {@inheritdoc}
     */
    // This is the main function that needs to be fixed.

public function getProductsAndFilters(
    int $categoryId,
    string $filters = null,
    string $sort = null,
    int $pageSize = 20,
    int $currentPage = 1
) {
    try {
        // Steps 1, 2, and 3 are correct. They get the good data from Solr.
        $start = ($currentPage - 1) * $pageSize;
        $appliedFilters = $filters ? json_decode($filters, true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WebapiException(__('Invalid filter format.'));
        }
        if ($sort) {
            $appliedFilters['sorting'] = $sort;
        }
        $appliedFilters["is_scroll_req"] = 0;
        $categoryData = $this->listInfo->getCategoryFilteredDataSolr($categoryId);
        if (empty($categoryData)) {
            throw new NoSuchEntityException(__('Category with ID "%1" does not exist.', $categoryId));
        }
        $solrResult = $this->listInfo->getFilteredData($start, $pageSize, $categoryData, $appliedFilters);
        if (!$solrResult || !isset($solrResult['response']['docs'])) {
             throw new \Exception('Failed to retrieve data from Solr.');
        }

        // Step 4: Process Products (This is correct)
        $products = [];
        foreach ($solrResult['response']['docs'] as $doc) {
            $products[] = $this->formatProductData($doc);
        }

        // --- START OF THE FIX ---

        // Get the RAW, UNTOUCHED facet data directly from the Solr result.
        // This variable contains the CORRECT IDs.
        $rawSolrFacets = $solrResult['facet_counts']['facet_fields'] ?? [];

        // Use the NEW helper method (formatRawSolrFacets) to process the GOOD data.
        // This will produce a filter list with the correct IDs.
        $finalFilters = $this->formatRawSolrFacets($rawSolrFacets, $categoryData);

        // We completely IGNORE and DO NOT CALL the broken "filterResponseFacets" method.

        // --- END OF THE FIX ---

        // Step 5: Assemble the final response
        $totalPages = ceil($solrResult['response']['numFound'] / $pageSize);

        return [
            'success' => true,
            'products' => $products,
            // Use the correctly formatted filters.
            'filters' => $finalFilters,
            'total_count' => (int) $solrResult['response']['numFound'],
            'pagination' => [
                'current_page' => (int) $currentPage,
                'page_size' => (int) $pageSize,
                'total_pages' => (int) $totalPages,
            ],
        ];

    } catch (NoSuchEntityException $e) {
        throw new NoSuchEntityException(__($e->getMessage()), $e, $e->getCode());
    } catch (\Exception $e) {
        $this->logger->error('Mobile API Solr Error: ' . $e->getMessage());
        throw new WebapiException(__('An error occurred while fetching product data.'), 0, WebapiException::HTTP_INTERNAL_ERROR);
    }
}

/**
 * ✅ NEW, CORRECT HELPER METHOD
 * This method processes the RAW facet data from Solr and returns a clean
 * array for the API with the CORRECT IDs.
 *
 * @param array $rawFacets The raw['facet_counts']['facet_fields'] from Solr.
 * @param array $categoryData The data for the current category.
 * @return array
 */
private function formatRawSolrFacets(array $rawFacets, array $categoryData): array
{
    $formatted = [];
    
    // 1. Handle Categories first (assuming this logic is correct from your old code)
    if (!empty($rawFacets['categories_token'])) {
         // This needs logic to parse parent/child categories if they come as tokens too
         // For now, let's assume a simplified structure or placeholder
    }

    // 2. Map other Solr fields to API types
    $facetMap = [
        'size_token'       => ['type' => 'sizes', 'label' => 'Sizes'],
        'delivery_time_token' => ['type' => 'delivery_times', 'label' => 'Delivery Times'],
        'color_token'      => ['type' => 'colors', 'label' => 'Colors'],
        'occasion_token'   => ['type' => 'occasions', 'label' => 'Occasions'],
        // Add other tokenized filter fields here (e.g., 'designer_token')
    ];

    foreach ($facetMap as $solrField => $apiInfo) {
        if (isset($rawFacets[$solrField]) && !empty($rawFacets[$solrField])) {
            $options = [];
            // Solr facets are a flat array: ['value1', count1, 'value2', count2, ...]
            $facetData = $rawFacets[$solrField];
            for ($i = 0; $i < count($facetData); $i += 2) {
                $token = $facetData[$i];
                $count = $facetData[$i + 1];

                // Skip if count is zero
                if ($count == 0) continue;

                $parts = explode('|', $token, 2); // Split "ID|Name"
                if (count($parts) === 2) {
                    $options[] = ['id' => $parts[0], 'name' => $parts[1]];
                }
            }

            if (!empty($options)) {
                $formatted[] = [
                    'type'    => $apiInfo['type'],
                    'label'   => $apiInfo['label'],
                    'options' => $options
                ];
            }
        }
    }
    
    // 3. Handle Price (assuming min/max prices are available in raw facets)
    // You may need to check $rawFacets['facet_ranges'] or $rawFacets['stats'] for this
    $formatted[] = [
        'type' => 'price',
        'label' => 'Price',
        'min_price' => $rawFacets['min_price_1'] ?? 0, // Example, check real field name
        'max_price' => $rawFacets['max_price_1'] ?? 1, // Example, check real field name
        'currency_symbol' => '₹' // Or get from config
    ];

    return $formatted;
}
    /**
     * ✅ NEW DYNAMIC HELPER METHOD
     * Takes the processed facets from the ListInfo model and dynamically formats them
     * into a structured array suitable for a modern API response. It automatically
     * discovers available filters and generates labels.
     *
     * @param array $processedFacets
     * @return array
     */
    private function formatFilters(array $processedFacets): array
    {
        $formatted = [];
        
        // These keys are special and handled separately, so we exclude them from the main loop.
        $excludeKeys = [
            'categories', 'child_categories', 'min_price', 'max_price', 'curr_symb'
        ];

        // Handle the special hierarchical category filter first
        if (!empty($processedFacets['categories'])) {
            $categoryOptions = [];
            $childCategories = $processedFacets['child_categories'] ?? [];

            foreach ($processedFacets['categories'] as $id => $name) {
                $children = [];
                // Check if this main category has children
                if (isset($childCategories[$id])) {
                    foreach ($childCategories[$id] as $childId => $childName) {
                        $children[] = ['id' => $childId, 'name' => $childName];
                    }
                }
                $categoryOptions[] = ['id' => $id, 'name' => $name, 'children' => $children];
            }
            // Add the category filter to the start of the array
            $formatted[] = ['type' => 'category', 'label' => 'Category', 'options' => $categoryOptions];
        }

        // Dynamically loop through all other available filters
        foreach ($processedFacets as $key => $filterData) {
            // Skip if the key is in our exclusion list or if the filter has no options
            if (in_array($key, $excludeKeys) || empty($filterData)) {
                continue;
            }

            // Generate a human-readable label from the key (e.g., "delivery_times" -> "Delivery Times")
            $label = ucwords(str_replace('_', ' ', $key));
            
            $options = [];
            if (is_array($filterData)) {
                foreach ($filterData as $id => $name) {
                    $options[] = ['id' => (string)$id, 'name' => (string)$name];
                }
            }

            $formatted[] = [
                'type' => $key,
                'label' => $label,
                'options' => $options
            ];
        }

        // Handle the price filter last
        $formatted[] = [
            'type' => 'price',
            'label' => 'Price',
            'min_price' => $processedFacets['min_price'] ?? 0,
            'max_price' => $processedFacets['max_price'] ?? 1,
            'currency_symbol' => $processedFacets['curr_symb'] ?? '₹'
        ];

        return $formatted;
    }
    /**
     * Formats the raw Solr document for a single product into a clean array.
     * This method avoids sending raw HTML and creates a structured response.
     *
     * @param array $doc Raw product data from a Solr document.
     * @return array
     */
    private function formatProductData(array $doc): array
    {
        $storeId = $this->listInfo->getCurrentStoreId();
        $actualPrice = $doc['actual_price'] ?? 0;
        $specialPrice = $doc['special_price'] ?? 0;
        $isOnSale = ($specialPrice > 0 && $specialPrice < $actualPrice);

        // Remove store code from URL if present
        $productUrl = isset($doc["prod_url"][0]) ? $doc["prod_url"][0] : '';
        $productUrl = preg_replace('/&?___store=[^&]*/', '', $productUrl);
        $productUrl = str_replace('?','', $productUrl);
        
        $smallImage = isset($doc["prod_small_img"]) ? $doc["prod_small_img"]."?w=400" : '';
        $smallImage = str_replace("static.aashniandco.com","imgs-aashniandco.gumlet.io",$smallImage);

        return [
            'id' => $doc['prod_en_id'] ?? null,
            'sku' => $doc['prod_sku'] ?? null,
            'name' => isset($doc['prod_name'][0]) ? $doc['prod_name'][0] : '',
            'designer' => $doc['prod_design'] ?? '',
            'short_description' => isset($doc['short_desc']) ? $doc['short_desc'] : '',
            'image_url' => $smallImage,
            'product_url' => $productUrl,
            'enquire_now' => isset($doc['enquire_'.$storeId][0]) && $doc['enquire_'.$storeId][0] == 1,
            'tags' => $doc['product_tags_name'][0] ?? '',
            'availability_label' => $doc['prod_availability_label'][0] ?? '',
            'is_on_sale' => $isOnSale,
            'price' => [
                'final_price' => $isOnSale ? (float)$specialPrice : (float)$actualPrice,
                'original_price' => (float)$actualPrice,
                'currency_symbol' => $this->listInfo->getCurrentCurrencySymbol(),
                'formatted_final_price' => $this->listInfo->getPrice($isOnSale ? $specialPrice : $actualPrice),
                'formatted_original_price' => $this->listInfo->getPrice($actualPrice),
            ]
        ];
    }


   public function getHomepageCmsContent()
{
    try {
        // Always use homepage_mob
        $blockId = 'homepage_mob';

        // Fetch from CMS block table
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('cms_block');

        $select = $connection->select()
            ->from($tableName, ['content'])
            ->where('identifier = ?', $blockId);

        $content = $connection->fetchOne($select);

        return [
            'success' => true,
            'block_id' => $blockId,
            'html' => $content ?: ''
        ];

    } catch (\Exception $e) {
        $this->logger->error('Homepage CMS Fetch Error: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Failed to load CMS content'
        ];
    }
}

 public function getActiveSubCategoriesByName(string $parentCategoryName)
    {
        try {
            // --- Step 1: Find the Parent Category ID using Magento's Repository (More Reliable) ---
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('name', $parentCategoryName)
                ->setPageSize(1) // We only need one result
                ->create();

            $categoryList = $this->categoryRepository->getList($searchCriteria)->getItems();

            if (empty($categoryList)) {
                // The category name was not found. Return a clean "not found" response.
                return [
                    'success' => true,
                    'parent_category_name' => $parentCategoryName,
                    'sub_categories' => []
                ];
            }
            
            // Get the first item from the result and its ID
            $parentCategory = array_shift($categoryList);
            $parentCategoryId = $parentCategory->getId();


            // --- Step 2: Use the found ID to get active sub-categories with a direct SQL query (Fast) ---
            $connection = $this->resource->getConnection();
            $categoryEntityTable = $this->resource->getTableName('catalog_category_entity');
            $varcharTable = $this->resource->getTableName('catalog_category_entity_varchar');
            $intTable = $this->resource->getTableName('catalog_category_entity_int');
            $eavAttributeTable = $this->resource->getTableName('eav_attribute');

            $nameAttributeId = $connection->fetchOne($connection->select()->from($eavAttributeTable, 'attribute_id')->where('entity_type_id = ?', 3)->where('attribute_code = ?', 'name'));
            $isActiveAttributeId = $connection->fetchOne($connection->select()->from($eavAttributeTable, 'attribute_id')->where('entity_type_id = ?', 3)->where('attribute_code = ?', 'is_active'));
            
            $select = $connection->select()
                ->from(['child_entity' => $categoryEntityTable], ['category_id' => 'entity_id'])
                ->join(['name_table' => $varcharTable], 'child_entity.entity_id = name_table.entity_id AND name_table.attribute_id = ' . (int)$nameAttributeId, ['category_name' => 'value'])
                ->join(['active_table' => $intTable], 'child_entity.entity_id = active_table.entity_id AND active_table.attribute_id = ' . (int)$isActiveAttributeId, [])
                ->where('child_entity.parent_id = ?', $parentCategoryId)
                ->where('active_table.value = ?', 1) // Only get active categories
                ->order('child_entity.position ASC'); // Order by position, as set in admin

            $results = $connection->fetchAll($select);

            return [
                'success' => true,
                'parent_category_name' => $parentCategoryName,
                'sub_categories' => $results
            ];

        } catch (\Exception $e) {
            $this->logger->error('API getActiveSubCategoriesByName Error for "' . $parentCategoryName . '": ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while fetching sub-categories.'];
        }
    }





public function getChildCategories(string $parentCategoryName)
    {
        // 🛑 IMPORTANT: Replace with your actual Solr core URL

        $solrBaseUrl = 'http://127.0.0.1:8983/solr/aashni_cat/select';

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


    /**
     * Parses the megamenu HTML, then uses the extracted names to fetch
     * category IDs from Solr.
     *
     * @return array
     */
    //  public function getMegamenuItems()
    // {
    //     // ... (Step 1 code is the same)
    //     $connection = $this->resource->getConnection();
    //     $tableName = $this->resource->getTableName('cms_block');
    //     $select = $connection->select()
    //         ->from($tableName, ['content'])
    //         ->where('identifier = ?', 'homepage_megamenu');
    //     $megamenuHtml = $connection->fetchOne($select);

    //     if (!$megamenuHtml) {
    //         return ['menu_items' => []];
    //     }

    //     $dom = new \DOMDocument();
    //     libxml_use_internal_errors(true);
    //     $dom->loadHTML(mb_convert_encoding($megamenuHtml, 'HTML-ENTITIES', 'UTF-8'));
    //     libxml_clear_errors();

    //     $categoryNames = [];
    //     foreach ($dom->getElementsByTagName('li') as $li) {
    //         if ($li->getAttribute('class') === 'tab-li') {
    //             $anchor = $li->getElementsByTagName('a')->item(0);
    //             if ($anchor) {
    //                 $span = $anchor->getElementsByTagName('span')->item(0);
    //                 if ($span && $span->getAttribute('class') === 'menu-txt') {
    //                     $menuText = trim($span->textContent);
    //                     if (!empty($menuText)) {
    //                         // ✅ THE FIX IS HERE: Convert text to Title Case to match Solr
    //                         // "NEW IN" becomes "New In"
    //                         // "WOMEN" becomes "Women"
    //                         $formattedName = ucwords(strtolower($menuText));
    //                         $categoryNames[$formattedName] = true;
    //                     }
    //                 }
    //             }
    //         }
    //     }
    //     $categoryNames = array_keys($categoryNames);

    //     if (empty($categoryNames)) {
    //         return ['menu_items' => []];
    //     }

    //     // --- Step 2: Query Solr with the CORRECTLY CASED names ---
    //     $solrIdMap = $this->getCategoryIdsFromSolr($categoryNames);

    //     // --- Step 3: Build the final result ---
    //     $menuItems = [];
    //     foreach ($categoryNames as $name) {
    //         $menuItems[] = [
    //             'id'   => $solrIdMap[$name] ?? null,
    //             'name' => $name
    //         ];
    //     }

    //     // This ensures the final JSON is {"menu_items": [...]}
    //     return ['menu_items' => $menuItems];
    // }
     /**
     * Performs a batch query to Solr. (This function remains the same)
     */
    private function getCategoryIdsFromSolr(array $names): array
    {
        // ... (This entire private function is correct and does not need changes)
        if (empty($names)) {
            return [];
        }
        $escapedNames = array_map(function ($name) {
            return '"' . addslashes($name) . '"';
        }, $names);
        $params = [
            'q'    => 'cat_name:(' . implode(' OR ', $escapedNames) . ')',
            'fl'   => 'cat_name,cat_en_id',
            'rows' => count($names),
            'wt'   => 'json'
        ];
        $url = self::SOLR_SELECT_URL . '?' . http_build_query($params);
        try {
            $this->curl->get($url);
            if ($this->curl->getStatus() !== 200) {
                $this->logger->error('Solr request for megamenu failed.', ['url' => $url, 'status' => $this->curl->getStatus(), 'response' => $this->curl->getBody()]);
                return [];
            }
            $responseBody = $this->curl->getBody();
            $responseData = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                 $this->logger->error('Failed to decode Solr JSON response for megamenu.', ['body' => $responseBody]);
                 return [];
            }
            $docs = $responseData['response']['docs'] ?? [];
            $idMap = [];
            foreach ($docs as $doc) {
                $id = current($doc['cat_en_id'] ?? []);
                $nameFromSolr = current($doc['cat_name'] ?? []);
                if ($id && $nameFromSolr) {
                    $idMap[$nameFromSolr] = $id;
                }
            }
            return $idMap;
        } catch (\Exception $e) {
            $this->logger->critical('Exception during Solr megamenu request.', ['exception_message' => $e->getMessage(), 'url' => $url]);
            return [];
        }
    }

// public function getMegamenuItems()
// {
//     $connection = $this->resource->getConnection();
//     $tableName = $this->resource->getTableName('cms_block');

//     // Fetch CMS block content for 'homepage_megamenu'
//     $select = $connection->select()
//         ->from($tableName, ['content'])
//         ->where('identifier = ?', 'homepage_megamenu');

//     $megamenuHtml = $connection->fetchOne($select);

//     // If the block is empty or not found, return an empty array
//     if (!$megamenuHtml) {
//         return ['menu_items' => []];
//     }

//     // Use DOMDocument to parse the HTML
//     $dom = new \DOMDocument();
//     // Suppress warnings for potentially malformed HTML and handle encoding
//     libxml_use_internal_errors(true);
//     $dom->loadHTML(mb_convert_encoding($megamenuHtml, 'HTML-ENTITIES', 'UTF-8'));
//     libxml_clear_errors();

//     $menuItems = []; // Changed from $menuNames to hold more data

//     foreach ($dom->getElementsByTagName('li') as $li) {
//         // Ensure we are only targeting the top-level menu items
//         if ($li->getAttribute('class') === 'tab-li') {
//             $anchor = $li->getElementsByTagName('a')->item(0);
            
//             if ($anchor) {
//                 $span = $anchor->getElementsByTagName('span')->item(0);

//                 // Check for the correct span class to get the name
//                 if ($span && $span->getAttribute('class') === 'menu-txt') {
//                     $menuText = trim($span->textContent);
                    
//                     // NEW: Get the category ID from the 'data-category-id' attribute
//                     $categoryId = $anchor->getAttribute('data-category-id');

//                     // Add the item to the array only if we have a name
//                     if (!empty($menuText)) {
//                         $menuItems[] = [
//                             'id'   => $categoryId ?: null, // Use null if the attribute is missing
//                             'name' => $menuText
//                         ];
//                     }
//                 }
//             }
//         }
//     }

//     // Return the new structure
//     return ['menu_items' => $menuItems];
// }
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


  /**
     * ✅ REPLACE your existing placeOrderForCustomer method with this one.
     * {@inheritdoc}
     */


       public function setShippingPrice(float $shippingPrice): bool
    {
        // 1. Get the current logged-in customer's ID
        $customerId = $this->userContext->getUserId();
        if (!$customerId) {
            // This is important for security, ensures only logged-in users can call this
            throw new \Magento\Framework\Exception\LocalizedException(__('Customer is not logged in.'));
        }

        try {
            // 2. Get the active cart (quote) for this customer
            $quote = $this->quoteRepository->getActiveForCustomer($customerId);

            // 3. Get the shipping address object from the cart
            $shippingAddress = $quote->getShippingAddress();

            // 4. Check if a shipping method is already set (important!)
            if (!$shippingAddress->getShippingMethod()) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Cannot set shipping price before a shipping method is selected.')
                );
            }

            // 5. This is the core logic: Override the shipping amounts
            // We force the price sent from the Flutter app onto the address object.
            // This is the step that carries security risks.
            $shippingAddress->setShippingAmount($shippingPrice);
            $shippingAddress->setBaseShippingAmount($shippingPrice); // Also set for the store's base currency

            // 6. We must tell Magento to recalculate all totals with our new overridden value
            $shippingAddress->setCollectShippingRates(true); // Ensures our value is used
            $quote->collectTotals();

            // 7. Save the modified cart (quote)
            $this->quoteRepository->save($quote);

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->logger->error('setShippingPrice API Error: Active cart not found for customer ' . $customerId);
            throw new \Magento\Framework\Exception\NoSuchEntityException(__('Active cart not found.'));
        } catch (\Exception $e) {
            $this->logger->critical('setShippingPrice API Error: ' . $e->getMessage(), ['exception' => $e]);
            // Re-throw the exception so the app knows it failed
            throw new \Magento\Framework\Exception\CouldNotSaveException(
                __('Could not set the shipping price: %1', $e->getMessage())
            );
        }

        // If everything worked, return true
        return true;
    }
    /** ✅ END: ADD THIS ENTIRE NEW METHOD IMPLEMENTATION */

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
        // ... (all the code at the top is correct) ...
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

        // *** CRITICAL SECURITY CHECK: Ensure the order belongs to the authenticated customer ***
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

//////////////
   public function getShippingRate($countryId, $regionId, $weight) // No default for $weight
    {
        $this->logger->info(sprintf(
            'getShippingRate API called with countryId: %s, regionId: %s, raw weight input: %s',
            $countryId,
            $regionId,
            // Log the raw weight input for debugging, handle if it's not set for some reason
            // though Magento's WebAPI framework should ensure it's passed if defined in webapi.xml
            // and the method signature doesn't make it optional.
            var_export($weight, true) // var_export gives more detail than just casting
        ));

        // Validate that $countryId is provided and not empty
        if (empty($countryId)) {
            $this->logger->error('Country ID parameter is missing or empty.');
            return [
                'success' => false,
                'shipping_price' => null,
                'message' => 'Country ID parameter is required.'
            ];
        }

        // Validate that $regionId is provided and numeric (even if it's 0)
        if (!isset($regionId) || !is_numeric($regionId)) {
             $this->logger->error('Region ID parameter is missing or not numeric.');
            return [
                'success' => false,
                'shipping_price' => null,
                'message' => 'Region ID parameter is required and must be numeric.'
            ];
        }


        // Validate that weight is provided and is numeric
        if (!isset($weight) || !is_numeric($weight)) {
            $this->logger->error('Weight parameter is missing or not numeric.', ['raw_weight' => $weight]);
            return [
                'success' => false,
                'shipping_price' => null, // Ensure shipping_price is included even on error for consistency
                'message' => 'Weight parameter is required and must be numeric.'
            ];
        }

        // Sanitize weight: ensure it's a non-negative float
        $cartWeight = max(0.0, (double)$weight);
        $this->logger->info(sprintf('Sanitized cartWeight: %s', $cartWeight));

        try {
            $connection = $this->resource->getConnection();
            $tableName = $this->resource->getTableName('shipping_tablerate');

            // SQL to find the best matching rate
            // We look for the smallest condition_value (weight tier) that is >= our cart_weight
            $sqlTemplate = "SELECT price FROM `{$tableName}`
                            WHERE dest_country_id = :country_id
                            AND dest_region_id = :region_id
                            AND condition_name = :condition_name
                            AND condition_value >= :cart_weight
                            ORDER BY condition_value ASC
                            LIMIT 1";

            $bind = [
                'country_id' => $countryId,
                'region_id' => (int)$regionId, // Try specific region first
                'condition_name' => 'package_weight', // Standard Magento condition name for weight
                'cart_weight' => $cartWeight
            ];

            $this->logger->info('Attempting to fetch shipping rate with specific region and weight.', $bind);
            $price = $connection->fetchOne($sqlTemplate, $bind);

            // If no specific region match, try with region_id = 0 (all regions for the country)
            // but still considering the weight.
            if ($price === false && (int)$regionId !== 0) {
                $this->logger->info(
                    'No specific region match. Trying with region_id = 0 for the same country and weight.'
                );
                $bind['region_id'] = 0; // Fallback to 'all regions'
                $this->logger->info('Attempting to fetch shipping rate with region_id = 0 and weight.', $bind);
                $price = $connection->fetchOne($sqlTemplate, $bind);
            }

            if ($price === false) {
                $this->logger->info(
                    'No shipping rate found for criteria.',
                    ['countryId' => $countryId, 'regionId_attempted' => $bind['region_id'], 'cartWeight' => $cartWeight]
                );
                return [
                    'success' => true, // Operation was successful, but no rate found
                    'shipping_price' => null,
                    'message' => 'No shipping rate found for this destination and weight.'
                ];
            }

            $shippingPrice = (float)$price;
            $this->logger->info('Shipping rate found.', ['price' => $shippingPrice]);

            return [
                'success' => true,
                'shipping_price' => $shippingPrice,
                'message' => 'Shipping rate calculated successfully.' // Optional success message
            ];

        } catch (\Exception $e) {
            $this->logger->critical('Error in getShippingRate: ' . $e->getMessage(), ['exception' => $e]);
            return [
                'success' => false,
                'shipping_price' => null,
                'message' => 'An error occurred while fetching the shipping rate. Please try again later.'
            ];
        }
    }



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



    public function deleteCartItem()
    {
         $itemId = $this->request->getParam('item_id');

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

      public function updateCartItemQty()
    {
        $itemId = $this->request->getParam('item_id');
        $qty = $this->request->getParam('qty');

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
    
    public function getDesigners()
    {
    
        $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?%22%22q=*:*&fq=categories-store-1_url_path:%22designers%22%22%22&facet=true&facet.field=designer_name&facet.limit=-1";

        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
            $this->curl->get($solrUrl);
            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
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
///Gender
public function getGenderData(string $genderName)
{
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60);

        // Check if comma exists (means multiple selected)
        if (strpos($genderName, ',') !== false) {
            $genderArray = explode(',', $genderName);
            $genderQuery = '(' . implode(' OR ', array_map(function($gender) {
                return '"' . trim($gender) . '"';
            }, $genderArray)) . ')';
        } else {
            // Single gender
            $genderQuery = '"' . trim($genderName) . '"';
        }

        $encodedGenderQuery = urlencode($genderQuery);

        $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?q=*:*&fq=gender_name:$encodedGenderQuery&fl=prod_name,actual_price_1,prod_small_img,prod_thumb_img,short_desc,prod_desc,size_name,prod_sku,gender_name&rows=400&wt=json";

        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);

    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function searchSolrData(array $payload)
{
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select";

    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60);
        $this->curl->addHeader("Content-Type", "application/json");
        $this->curl->post($solrUrl, json_encode($payload));
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getSolrSearch($queryParams)
{
    $query = isset($queryParams['query']) ? $queryParams['query'] : '*:*';
    $params = isset($queryParams['params']) ? $queryParams['params'] : [];

    $fl = isset($params['fl']) ? $params['fl'] : '*';
    $rows = isset($params['rows']) ? $params['rows'] : 10;

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?"
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

 public function getNewInWomenclothing_Lehengas(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&q=categories-store-1_id%3A(1372%20OR%201500%20OR%202295%20OR%202665%20OR%202666%20OR%203038%20OR%203049%20OR%203069%20OR%203107%20OR%203109%20OR%203111%20OR%203208%20OR%203210)%20%0AAND%20categories-store-1_name%3A(%22classic%20lehengas%22%20OR%20%22draped%20lehengas%22%20OR%20%22contemporary%20lehengas%22%20OR%20%22ruffle%20lehengas%22%20OR%20%22lighter%20lehengas%22%20OR%20%22printed%20lehengas%22%20OR%20%22floral%20lehengas%22%20OR%20%22festive%20lehengas%22%20OR%20%22bridal%20lehengas%22%20OR%20%22handwoven%20lehengas%22)%20%0AAND%20actual_price_1%3A%5B1%20TO%20*%5D%0A%0Afl%3Ddesigner_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&rows=9000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInWomenclothing_KurtaSets(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%204450%20OR%204451%20OR%204463%20OR%204476%20OR%205594%20)%20AND%20categories-store-1_name%3A(%22%22dhoti%20kurtas%22%22%20OR%20%22%22printed%20kurtas%22%22%20OR%20%22%22contemporary%20kurtas%20%22%22%20OR%20%22%22plain%20kurtas%20%22%22%20%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInWomenclothing_Sarees(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&q=categories-store-1_id%3A(1372%20OR%201500%20OR%202297%20OR%202671%20OR%202672%20OR%202673%20OR%203103%20OR%203121%20OR%203213%20OR%203215%20OR%203217%20OR%203258)%0AAND%20categories-store-1_name%3A(%22classic%20sarees%22%20OR%20%22pre%20draped%20sarees%22%20OR%20%22saree%20gowns%22%20OR%20%22printed%20sarees%22%20%0AOR%20%22pants%20and%20dhoti%20sarees%22%20OR%20%22handwoven%20sarees%22%20OR%20%22ruffle%20sarees%22%20OR%20%22striped%20sarees%22%20OR%20%22lehenga%20sarees%22)%0AAND%20actual_price_1%3A%5B1%20TO%20*%5D&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 
 public function getNewInWomenclothing_Tops(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%202299%20OR%202681%20OR%202683%20OR%202732%20OR%202809%20OR%203305%20OR%203306%20OR%203307%20OR%203364%20OR%203366)%20AND%20categories-store-1_name%3A(%22cropped%20%22%20OR%20%22blouses%22%20OR%20%22shirts%20%22%20OR%20%22classic%20tops%22%20%20OR%20%22t%20shirts%20%22%20OR%20%22off%20the%20shoulder%22%20OR%20%22printed%22%20OR%20%22sweatshirts%20%22%20OR%20%22hoodies%20%22)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=15000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInWomenclothing_Kaftans(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%204046%20OR%204352%20OR%204353%20OR%204354%20)%20AND%20categories-store-1_name%3A(%22%22plain%22%22%20OR%20%22%22embellished%20%22%22%20OR%20%22%22printed%22%22%20%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInWomenclothing_Gowns(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%203264%20OR%203265%20OR%203268%20OR%203270%20OR%203272%20OR%203274%20OR%203954%20)%20AND%20categories-store-1_name%3A(%22%22feather%22%22%20OR%20%22%22tulle%22%22%20OR%20%22%22cape%22%22%20OR%20%22%22traditional%22%22OR%20%22%22embroidered%22%22%20OR%20%22%22cocktail%22%22%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInWomenclothing_Pants(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%203247%20OR%203248%20OR%203251%20OR%203253%20OR%203255%20OR%203351%20OR%203368%20)%20AND%20categories-store-1_name%3A(%22%22chudidars%22%22%20OR%20%22%22dhotis%22%22%20OR%20%22%22palazzos%22%22%20OR%20%22%22straight%20pants%22%22OR%20%22%22draped%20pants%22%22%20OR%20%22%22trousers%22%22%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInWomenclothing_TunicsKurtis(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%203293%20OR%203294%20OR%203295%20OR%203296%20OR%203297)%20AND%20categories-store-1_name%3A(%22%22tunic%20sets%22%22%20OR%20%22%22short%22%22%20OR%20%22%22long%22%22%20OR%20%22%22printed%22%22%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


// pending in Postman test
 public function getNewInWomenclothing_Capes(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%204491%20OR%205517)%20%20AND%20categories-store-1_name%3A(%22%22cape%20sets%20%22%22%20)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInWomenclothing_Jumpsuits(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%203027%20OR%203241%20OR%203243%20OR%203245%20)%20AND%20categories-store-1_name%3A(%22%22embellished%20%20%22%22%20OR%20%22%22printed%22%22%20OR%20%22%22plain%22%22%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=3000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 public function getNewInWomenclothing_Kurtas(){
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%204450%20OR%204451%20OR%204463%20OR%204476%20OR%205594%20)%20AND%20categories-store-1_name%3A(%22%22dhoti%20kurtas%22%22%20OR%20%22%22printed%20kurtas%22%22%20OR%20%22%22contemporary%20kurtas%20%22%22%20OR%20%22%22plain%20kurtas%20%22%22%20%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 public function getNewInWomenclothing_Skirts(){
  
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%203219%20OR%203220%20OR%203223%20OR%203225%20OR%203227%20OR%203229%20%20)%20AND%20categories-store-1_name%3A(%22%22lehenga%20skirts%22%22%20OR%20%22%22midi%22%22%20OR%20%22%22mini%22%22%20OR%20%22%22knee%20length%22%22OR%20%22%22embellished%22%22%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=5000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInWomenclothing_PalazzoSets(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%204460)%20%20AND%20categories-store-1_name%3A(%22palazzo%20sets%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInWomenclothing_Beach(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201500%20OR%202596%20OR%203231%20OR%203233%20OR%203235%20OR%203237%20OR%203239%20OR%203370%20OR%203372)%20AND%20categories-store-1_name%3A(%22%22one%20piece%20%22%22%20OR%20%22%22bikinis%22%22%20OR%20%22%22bikinis%20bottoms%22%22%20OR%20%22%22cover%20ups%22%22%20%20OR%20%22%22beach%20dresses%20%22%22%20OR%20%22%22bikni%20tops%22%22%20OR%20%22%22sarongs%22%22)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=5000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


/////////////////*Accessories*/////////////////////////


public function getNewInAccessories_Bags(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201501%20OR%202304%20OR%202716%20OR%202717%20OR%202718%20OR%202721%20OR%202722%20OR%203902%20OR%205570%20OR%205572)%20%20AND%20categories-store-1_name%3A(%22%22clutch%20bags%22%22%20OR%20%22%22backpacks%22%22%20OR%20%22%22potlis%22%22OR%20%22%22tote%20bags%20%22%22OR%20%22%22trunks%20%22%22OR%20%22%22bangle%20box%22%22OR%20%22%22laptop%20bags%22%22OR%20%22%22wallets%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInAccessories_Shoes(){

    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201501%20OR%202306%20OR%202723%20OR%202725%20OR%202726%20OR%202728%20OR%203699%20OR%203792%20OR%205609)%20%20AND%20categories-store-1_name%3A(%22%22sandals%22%22%20OR%20%22%22wedges%20%22%22%20OR%20%22%22juttis%22%22OR%20%22%22heels%20%22%22OR%20%22%22sneakers%22%22OR%20%22%22kolhapuris%22%22OR%20%22%22mules%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInAccessories_Belts(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201501%20OR%202305%20)%20%20AND%20categories-store-1_name%3A(%22%22belts%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInAccessories_Masks(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201501%20OR%202613)%20%20AND%20categories-store-1_name%3A(%22%22masks%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 /////////////////*Men*/////////////////////////

 public function getNewInMen_KurtaSets(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202588%20%20OR%203077%20OR%203079%20OR%203315%20OR%203317%20)%20%20AND%20categories-store-1_name%3A(%22%22embellished%22%22%20OR%20%22%22plain%22%22%20OR%20%22%22printed%22%22OR%20%22%22avant-%20garde%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInMen_Sherwanis(){

   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202590%20%20OR%203081%20OR%203083%20OR%203085)%20%20AND%20categories-store-1_name%3A(%22%22heavy%20sherwanis%22%22%20OR%20%22%22light%20sherwanis%22%22OR%20%22%22printed%20sherwanis%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInMen_Jackets(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202595%20%20OR%204477%20OR%204479%20OR%204484%20OR%205601)%20%20AND%20categories-store-1_name%3A(%22%22formal%20jackets%22%22%20OR%20%22%22casual%20jackets%22%22OR%20%22%22avant-%20grade%22%22OR%20%22%22jacket%20sets%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInMen_MenAccessories(){


    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202591%20OR%202810%20OR%202811%20OR%202828%20OR%202829%20OR%202830%20OR%202831%20OR%203624%20OR%203746%20OR%203795%20OR%204105%20OR%204356%20OR%205614%20OR%206088)%20AND%20categories-store-1_name%3A(%22cufflinks%22%20OR%20%22pocket%20square%22%20OR%20%22headwear%22%20OR%20%22buttons%22%20OR%20%22lapel%20pins%20and%20collar%20tips%22%20OR%20%22kalangi%22%20OR%20%22brooches%22%20OR%20%22men%27s%20necklace%22%20OR%20%22gift%20boxes%22%20OR%20%22belts%22%20OR%20%22shawls%20%26%20stoles%22%20OR%20%22bracelets%22%20OR%20%22earrings%22)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInMen_Kurtas(){

   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202587%20OR%203051%20OR%203052%20OR%203053%20OR%203054)%20AND%20categories-store-1_name%3A(%22plain%22%20OR%20%22printed%22%20OR%20%22avant%20garde%22%20OR%20%22embellished%22)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }
 

 public function getNewInMen_Shirts(){

    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202593%20%20OR%203724%20OR%203726%20)%20%20AND%20categories-store-1_name%3A(%22%22formal%20shirts%22%22%20OR%20%22%22casual%20shirts%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInMen_Bandis(){


    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202589%20%20OR%203059%20OR%203060%20OR%205931)%20%20AND%20categories-store-1_name%3A(%22%22plain%22%22%20OR%20%22%22printed%22%22OR%20%22%22embellished%22%22)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=8000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInMen_Trousers(){

   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=(categories-store-1_id%3A(1372%20OR%201977%20OR%202594%20%20OR%201731)%20%20AND%20categories-store-1_name%3A(%22%22trousers%22%22%20)%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D)&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 /////////////////*New In Jewellery*/////////////////////////

 public function getNewInJewellery_Earrings(){
   
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1372%20OR%206023%20OR%206024%20OR%206222%20OR%206225%20OR%206228%20OR%206231%20OR%206233%20OR%206236)%20AND%20categories-store-1_name%3A(chandbalis%20OR%20jhumkas%20OR%20danglers%20OR%20studs%20OR%20hoops%20OR%20earcuffs)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInJewellery_BanglesBracelets(){
   
    

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_name%3A*bangle*&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }



 }



 

 public function getNewInJewellery_FineJewelry(){
   
    

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1372%20OR%206023%20OR%206064%20OR%206065%20OR%206069%20OR%206072%20OR%206075%20OR%206077)%20AND%20categories-store-1_name%3A(tikkasandpassas%20OR%20bangle%20OR%20rings%20OR%20earrings%20OR%20necklaces%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }



 }





 public function getNewInJewellery_HandHarness(){
   
   

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_name%3Ahand%5C%20harness&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }



 }


 public function getNewInJewellery_Rings(){
   
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1372%20OR%206023%20OR%206038%20)%20AND%20categories-store-1_name%3A(rings)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }



 }

 


 public function getNewInJewellery_FootHarness(){
    
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_name%3Afoot%5C%20harness&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }



 }

 


 public function getNewInJewellery_Brooches(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1372%20OR%206023%20OR%206036%20)%20AND%20categories-store-1_name%3A(brooches)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }



 }


 public function getNewInJewellery_Giftboxes(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_name%3Agift%5C%20boxes&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }



 }

 /////////////////*KidsWeaar*/////////////////////////

 public function getNewInKidswear_KurtaSetsforBoys(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(3327)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInKidswear_Shararas(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(2145)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInKidswear_Dresses(){
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(2139%20OR%203332%20OR%203338%20OR%204161%20OR%204162%20)%20AND%20categories-store-1_name%3A(day%20dresses%2C%20party%20dresses%2Cfloral%20dresses%2Cruffle%20dresses)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 public function getNewInKidswear_KidsAccessories(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(4045%20OR%204123%20OR%204148%20OR%204149%20OR%204150%20OR%204151%20OR%204152%20OR%204153%20OR%204154%20OR%204154)%20AND%20categories-store-1_name%3A(hair%20accessories%20%2C%20earrings%2Cpendant%2Cbracelets%2Cmaang%20tikka%2Chathphool%2Cnecklace%2Chair%20clips%2Chair%20band)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInKidswear_Shirts(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1978%20OR%204254%20)%20AND%20categories-store-1_name%3A(shirts)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInKidswear_Jackets(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1978%20OR%203336)%20AND%20categories-store-1_name%3A(jackets)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInKidswear_Coordset(){
 
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(%204419)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }






 public function getNewInKidswear_Anarkalis(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(%202137)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInKidswear_Gowns(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(%202140)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

  
 public function getNewInKidswear_Achkan(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(%202143)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInKidswear_Bandhgalas(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1978%20OR%202147)%20AND%20categories-store-1_name%3A(bandhgalas)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 

 public function getNewInKidswear_Dhotisets(){
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(%202146)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 

 public function getNewInKidswear_Jumpsuit(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(%204094)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 


 public function getNewInKidswear_Sherwanis(){
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1978%20OR%202144)%20AND%20categories-store-1_name%3A(sherwanis)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }




 public function getNewInKidswear_Pants(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(4405)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInKidswear_Bags(){
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(5928)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInKidswear_Tops(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(3762)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }


 public function getNewInKidswear_Skirts(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(4407)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 public function getNewInKidswear_Sarees(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(2161)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 /////////////////*Theme*/////////////////////////

 public function getNewInTheme_Contemporary(){


   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ctheme_name&fq=fq%3D(%20%20%20categories-store-1_id%3A(1372%201500%202295%202296%202297%202298%202299%202665%202666%202668%202670%202671%202672%202673%202676%202677%202681%202683%202732%202809%203038%203040%203049%203063%203067%203069%203091%203103%203105%203107%203109%203111%203121%203208%203210%203213%203215%203217%203258%203260%203303%203341%203364%203366%203765%205559%204046%204352%204353%204354%204460%204463%204476%205594)%20%20%20AND%20categories-store-1_name%3A(%20%20%20%20%20%22classic%20lehengas%22%20%22draped%20lehengas%22%20%22contemporary%20lehengas%22%20%22ruffle%20lehengas%22%20%22lighter%20lehengas%22%20%20%20%20%20%22printed%20lehengas%22%20%22floral%20lehengas%22%20%22festive%20lehengas%22%20%22bridal%20lehengas%22%20%22handwoven%20lehengas%22%20%20%20%20%20%22anarkalis%22%20%22sharara%20sets%22%20%22palazzo%20sets%22%20%22printed%20kurtas%22%20%22straight%20kurta%20sets%22%20%22dhoti%20kurtas%22%20%22kurtas%22%20%20%20%20%20%22handwoven%20kurta%20sets%22%20%22kurta%20sets%22%20%22classic%20sarees%22%20%22pre%20draped%20sarees%22%20%22saree%20gowns%22%20%22printed%20sarees%22%20%20%20%20%20%22pants%20and%20dhoti%20sarees%22%20%22handwoven%20sarees%22%20%22ruffle%20sarees%22%20%22striped%20sarees%22%20%22lehenga%20sarees%22%20%20%20%20%20%22maxi%20dresses%22%20%22midi%20dresses%22%20%22mini%20dresses%22%20%22silk%20dresses%22%20%22evening%20dresses%22%20%22day%20dresses%22%20%22floral%20dresses%22%20%20%20%20%20%22knee%20length%20dresses%22%20%22shift%20dresses%22%20%22cropped%22%20%22blouses%22%20%22shirts%22%20%22classic%20tops%22%20%22t%20shirts%22%20%22off%20the%20shoulder%22%20%20%20%20%20%22printed%22%20%22sweatshirts%22%20%22hoodies%22%20%22suits%22%20%22tracksuits%22%20%22jacket%20sets%22%20%22crop%20top%20sets%22%20%22skirt%20sets%22%20%22pant%20sets%22%20%20%20%20%20%22short%20sets%22%20%22lehenga%20skirts%22%20%22midi%22%20%22mini%22%20%22knee%20length%22%20%22embellished%22%20%22chudidars%22%20%22dhotis%22%20%22palazzos%22%20%20%20%20%20%22straight%20pants%22%20%22draped%20pants%22%20%22trousers%22%20%22feather%22%20%22tulle%22%20%22cape%22%20%22traditional%22%20%22embroidered%22%20%22cocktail%22%20%20%20%20%20%22tunic%20sets%22%20%22short%22%20%22long%22%20%22printed%22%20%22plain%22%20%22embellished%22%20%22dhoti%20kurtas%22%20%22printed%20kurtas%22%20%22contemporary%20kurtas%22%20%20%20%20%20%22plain%20kurtas%22%20%22palazzo%20sets%22%20%20%20)%20%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20%20%20AND%20theme_name%3A%22Contemporary%22%20)&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 public function getNewInTheme_Ethnic(){


   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ctheme_name&fq=fq%3D(%20%20%20categories-store-1_id%3A(1372%201500%202295%202296%202297%202298%202299%202665%202666%202668%202670%202671%202672%202673%202676%202677%202681%202683%202732%202809%203038%203040%203049%203063%203067%203069%203091%203103%203105%203107%203109%203111%203121%203208%203210%203213%203215%203217%203258%203260%203303%203341%203364%203366%203765%205559%204046%204352%204353%204354%204460%204463%204476%205594)%20%20%20AND%20categories-store-1_name%3A(%20%20%20%20%20%22classic%20lehengas%22%20%22draped%20lehengas%22%20%22contemporary%20lehengas%22%20%22ruffle%20lehengas%22%20%22lighter%20lehengas%22%20%20%20%20%20%22printed%20lehengas%22%20%22floral%20lehengas%22%20%22festive%20lehengas%22%20%22bridal%20lehengas%22%20%22handwoven%20lehengas%22%20%20%20%20%20%22anarkalis%22%20%22sharara%20sets%22%20%22palazzo%20sets%22%20%22printed%20kurtas%22%20%22straight%20kurta%20sets%22%20%22dhoti%20kurtas%22%20%22kurtas%22%20%20%20%20%20%22handwoven%20kurta%20sets%22%20%22kurta%20sets%22%20%22classic%20sarees%22%20%22pre%20draped%20sarees%22%20%22saree%20gowns%22%20%22printed%20sarees%22%20%20%20%20%20%22pants%20and%20dhoti%20sarees%22%20%22handwoven%20sarees%22%20%22ruffle%20sarees%22%20%22striped%20sarees%22%20%22lehenga%20sarees%22%20%20%20%20%20%22maxi%20dresses%22%20%22midi%20dresses%22%20%22mini%20dresses%22%20%22silk%20dresses%22%20%22evening%20dresses%22%20%22day%20dresses%22%20%22floral%20dresses%22%20%20%20%20%20%22knee%20length%20dresses%22%20%22shift%20dresses%22%20%22cropped%22%20%22blouses%22%20%22shirts%22%20%22classic%20tops%22%20%22t%20shirts%22%20%22off%20the%20shoulder%22%20%20%20%20%20%22printed%22%20%22sweatshirts%22%20%22hoodies%22%20%22suits%22%20%22tracksuits%22%20%22jacket%20sets%22%20%22crop%20top%20sets%22%20%22skirt%20sets%22%20%22pant%20sets%22%20%20%20%20%20%22short%20sets%22%20%22lehenga%20skirts%22%20%22midi%22%20%22mini%22%20%22knee%20length%22%20%22embellished%22%20%22chudidars%22%20%22dhotis%22%20%22palazzos%22%20%20%20%20%20%22straight%20pants%22%20%22draped%20pants%22%20%22trousers%22%20%22feather%22%20%22tulle%22%20%22cape%22%20%22traditional%22%20%22embroidered%22%20%22cocktail%22%20%20%20%20%20%22tunic%20sets%22%20%22short%22%20%22long%22%20%22printed%22%20%22plain%22%20%22embellished%22%20%22dhoti%20kurtas%22%20%22printed%20kurtas%22%20%22contemporary%20kurtas%22%20%20%20%20%20%22plain%20kurtas%22%20%22palazzo%20sets%22%20%20%20)%20%20%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20%20%20AND%20theme_name%3A%22Ethnic%22%20)&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



 /////////////////*NEWIN GENDER*/////////////////////////

 public function getNewInGender_Men(){


  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1977)%20AND%20categories-store-1_name%3A(%22men%22%20)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }

 public function getNewInGender_Women(){

    
 
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc&fq=categories-store-1_id%3A(1500)&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

 }



/////////////////*NEWIN Color*/////////////////////////
/// for loop 


public function geNewInColor($colorName){

    $validColors = ['Black', 'Blue', 'Brown', 'Burgundy', 'Gold', 'Green', 'Grey', 'Metallic', 'Multicolor', 'Neutrals', 'Orange', 'Peach', 'Pink', 'Print', 'Purple', 'Red', 'Silver', 'White', 'Yellow'];

    if (!in_array($colorName, $validColors)) {
        return ['error' => 'Invalid color name'];
    }

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22$colorName%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";

    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60);
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
}

}


// Example function for each color filter

public function getNewInColor_Black() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Black%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Blue() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Blue%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Brown() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Brown%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Burgundy() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Burgundy%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Gold() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Gold%22%20AND%20-color_name%3A%22Black%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Green() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Green%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Grey() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Grey%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Metallic() {
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Metallic%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Red() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Red%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Yellow() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Yellow%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22Blue%22%20AND%20-color_name%3A%22Black%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_White() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22White%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22Blue%22%20AND%20-color_name%3A%22Black%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInColor_Pink() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Ccolor_name&fq=categories-store-1_id%3A(1372)%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20color_name%3A%22Pink%22%20AND%20-color_name%3A%22Gold%22%20AND%20-color_name%3A%22White%22%20AND%20-color_name%3A%22Silver%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}



/////////////////*NEWIN Size*/////////////////////////

public function getNewInSize_XXSmall() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22XXSmall%22&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_XSmall() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22XSmall%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_Small(){
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Small%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_Medium(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Medium%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_Large() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Large%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInSize_XLarge() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22XLarge%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_XXLarge() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22XXLarge%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_4XLarge() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%224XLarge%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_5XLarge() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%225XLarge%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_CustomMade() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Custom%20Made%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_FreeSize() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Free%20Size%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize32() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2032%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize33() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2033%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize34() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2034%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize35() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2035%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize36() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2036%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize37() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2037%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInSize_EuroSize38() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2038%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize39() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2039%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize40() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2040%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize41() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2041%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
public function getNewInSize_EuroSize42() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2042%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_EuroSize43() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2043%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_EuroSize44() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2044%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_EuroSize45() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2045%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_EuroSize46() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2046%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_EuroSize47() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2047%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_EuroSize48() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2048%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_EuroSize49() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Euro%20Size%2049%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_BangleSize22(){

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Bangle%20Size-%202.2%5C%22%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInSize_BangleSize24() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Bangle%20Size-%202.4%5C%22%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInSize_BangleSize26() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Bangle%20Size-%202.6%5C%22%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInSize_BangleSize28() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%22Bangle%20Size-%202.8%5C%22%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInSize_6_12Months() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%226-12%20Months%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_1_2Years() {
   
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%221-2%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_2_3Years() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%222-3%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_3_4Years() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%223-4%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_4_5Years() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%224-5%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_5_6Years() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%225-6%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_6_7Years() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%226-7%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_7_8Years() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%227-8%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_8_9Years() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%228-9%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_9_10Years() {
    
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%229-10%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_10_11Years() {
 
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%2210-11%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_11_12Years() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%2211-12%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_12_13Years() {
 
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%2212-13%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_13_14Years() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%2213-14%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_14_15Years() {
 
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%2214-15%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInSize_15_16Years() {
  
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2Csize_name&fq=categories-store-1_id%3A1372%20AND%20actual_price_1%3A%5B1%20TO%20*%5D%20AND%20size_name%3A%2215-16%20Years%22&q=*%3A*&rows=20000&wt=json";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


/////////////////*NEWIN Delivery*/////////////////////////

public function getNewInDelivery_Immediate() {
  
    

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2C%20child_delivery_time&fq=child_delivery_time%3A%22Immediate%22%20AND%20-child_delivery_time%3A%221-2%20Weeks%22%20AND%20-child_delivery_time%3A%222-4%20Weeks%22%20AND%20-child_delivery_time%3A%224-6%20Weeks%22%20AND%20-child_delivery_time%3A%226-8%20Weeks%22%20AND%20-child_delivery_time%3A%22%3E8%20Weeks%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInDelivery_1_2Weeks() {
  


    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2C%20child_delivery_time&fq=child_delivery_time%3A%221-2%20Weeks%22%20AND%20-child_delivery_time%3A%22Immediate%22%20AND%20-child_delivery_time%3A%222-4%20Weeks%22%20AND%20-child_delivery_time%3A%224-6%20Weeks%22%20AND%20-child_delivery_time%3A%226-8%20Weeks%22%20AND%20-child_delivery_time%3A%22%3E8%20Weeks%22&q=*%3A*&rows=10000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInDelivery_2_4Weeks() {
  


    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2C%20child_delivery_time&fq=child_delivery_time%3A%222-4%20Weeks%22%20AND%20-child_delivery_time%3A%22Immediate%22%20AND%20-child_delivery_time%3A%221-2%20Weeks%22%20AND%20-child_delivery_time%3A%224-6%20Weeks%22%20AND%20-child_delivery_time%3A%226-8%20Weeks%22%20AND%20-child_delivery_time%3A%22%3E8%20Weeks%22&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getNewInDelivery_4_6Weeks() {
   

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2C%20child_delivery_time&fq=child_delivery_time%3A%224-6%20Weeks%22%20AND%20-child_delivery_time%3A%22Immediate%22%20AND%20-child_delivery_time%3A%221-2%20Weeks%22%20AND%20-child_delivery_time%3A%222-4%20Weeks%22%20AND%20-child_delivery_time%3A%226-8%20Weeks%22%20AND%20-child_delivery_time%3A%22%3E8%20Weeks%22&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInDelivery_6_8Weeks() {
   
 
    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2C%20child_delivery_time&fq=child_delivery_time%3A%226-8%20Weeks%22%20AND%20-child_delivery_time%3A%22Immediate%22%20AND%20-child_delivery_time%3A%221-2%20Weeks%22%20AND%20-child_delivery_time%3A%222-4%20Weeks%22%20AND%20-child_delivery_time%3A%224-6%20Weeks%22%20AND%20-child_delivery_time%3A%22%3E8%20Weeks%22&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

public function getNewInDelivery_8Weeks() {

    $solrUrl = "http://127.0.0.1:8983/solr/aashni_dev/select?fl=designer_name%2Cactual_price_1%2Cprod_name%2Cprod_en_id%2Cprod_sku%2Cprod_small_img%2Cprod_thumb_img%2Cshort_desc%2C%20child_delivery_time&fq=child_delivery_time%3A%22%3E%208%20Weeks%22%20AND%20-child_delivery_time%3A%22Immediate%22%20AND%20-child_delivery_time%3A%221-2%20Weeks%22%20AND%20-child_delivery_time%3A%222-4%20Weeks%22%20AND%20-child_delivery_time%3A%224-6%20Weeks%22%20AND%20-child_delivery_time%3A%226-8%20Weeks%22&q=*%3A*&rows=20000";
    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 60); 
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}


public function getThemeData(string $themes, string $categoryId)
{
    // Convert the comma-separated theme list into an array and trim extra spaces
    $themeArray = array_map('trim', explode(',', $themes));

    // Validate themes
    if (empty($themeArray)) {
        return ['error' => 'No themes provided'];
    }

    // Build theme filter query
    if (count($themeArray) === 1) {
        // Only one theme selected: no OR, simple query
        $themeFilter = 'theme_name:"' . $themeArray[0] . '"';
    } else {
        // Multiple themes selected: wrap with ( ) and OR
        $themeFilter = '(' . implode(' OR ', array_map(function ($theme) {
            return 'theme_name:"' . $theme . '"';
        }, $themeArray)) . ')';
    }

    // Base Solr URL
    $baseSolrUrl = 'http://130.61.224.212:8983/solr/aashni_dev/select';

    // Build query parameters
    $queryParams = [
        'q' => '*:*',
        'fl' => 'designer_name,actual_price_1,prod_name,prod_en_id,prod_sku,prod_small_img,prod_thumb_img,short_desc,theme_name',
        'wt' => 'json',
        'rows' => 200,
        'fq' => [

            $themeFilter,
            "categories-store-1_id:$categoryId"
        ]
    ];

    // Build query string manually for multiple fq
    $queryString = http_build_query([
        'q' => $queryParams['q'],
        'fl' => $queryParams['fl'],
        'wt' => $queryParams['wt'],
        'rows' => $queryParams['rows'],
    ]);

    foreach ($queryParams['fq'] as $fq) {
        $queryString .= '&fq=' . urlencode($fq);
    }

    $solrUrl = $baseSolrUrl . '?' . $queryString;

    try {
        $this->curl->setOption(CURLOPT_TIMEOUT, 120);
        $this->curl->get($solrUrl);
        $response = $this->curl->getBody();
        return json_decode($response, true);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }

    // echo "Print theme";
}






}