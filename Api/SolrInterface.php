<?php


namespace Aashni\MobileApi\Api;

/**
 * Interface for providing Solr product and filter data for listing pages.
 * @api
 */

interface SolrInterface
{


   

      /**
     * ✅ NEW METHOD DEFINITION
     * Get basic category metadata by its URL key (e.g., "men", "accessories").
     *
     * This is a high-performance lookup endpoint.
     *
     * @param string $urlKey The URL key of the category.
     * @return array The category's metadata, including its ID.
     * @throws \Magento\Framework\Exception\NoSuchEntityException If no category is found for the given URL key.
     */
    public function getCategoryByUrlKey(string $urlKey);

        /**
     * Get ONLY the filterable facets for a given category from Solr.
     *
     * This is a lightweight, high-performance endpoint designed to populate filter menus
     * before the user has requested any products.
     *
     * @param int $categoryId The ID of the category to fetch filters for.
     * @return array The raw, structured filter data as provided by the business logic.
     * @throws \Magento\Framework\Exception\NoSuchEntityException If the category does not exist.
     */
    public function getFilters(int $categoryId);


      /**
     * Get products and filterable facets for a given category from Solr.
     *
     * @param int $categoryId The ID of the category to fetch products for.
     * @param string|null $filters A JSON-encoded string of applied filters. E.g., '{"designerFilter":["123"],"colorFilter":["45","67"]}'
     * @param string|null $sort The sorting option. E.g., 'high-to-low', 'low-to-high'.
     * @param int $pageSize The number of products to return per page.
     * @param int $currentPage The current page number for pagination.
     * @return array The structured response containing products, filters, and pagination data.
     * @throws \Magento\Framework\Exception\NoSuchEntityException If the category does not exist.
     * @throws \Exception For other processing errors.
     */
    public function getProductsAndFilters(
        int $categoryId,
        string $filters = null,
        string $sort = null,
        int $pageSize = 20,
        int $currentPage = 1
    );



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
     * Fetch products by Gender Name (Men/Women)
     *
     * @param string $genderName
     * @return array
     */
    public function getGenderData(string $genderName);


     /**
     * @param mixed $queryParams
     * @return array
     */
    public function getSolrSearch($queryParams);


   /**
     * Get Solr NewIn-Accessories data
     *
     * @return array
     */
    public function getNewInAccessories();

     /**
     * Search Solr based on dynamic JSON parameters
     *
     * @param mixed[] $payload
     * @return array
     */
    public function searchSolrData(array $payload);

  // By Mahesh Gadri 5/4/2025

   /**
     * Get Solr NewIn-Womenclothing Lehengas
     *
     * @return array
     */
    public function getNewInWomenclothing_Lehengas();


     /**
     * Get Solr NewIn-Womenclothing Kurta Sets
     *
     * @return array
     */
    public function getNewInWomenclothing_KurtaSets();


     /**
     * Get Solr NewIn-Womenclothing Sarees
     *
     * @return array
     */
    public function getNewInWomenclothing_Sarees();

      /**
     * Get Solr NewIn-Womenclothing Sarees
     *
     * @return array
     */
    public function getNewInWomenclothing_Tops();



       /**
     * Get Solr NewIn-Womenclothing Kaftans
     *
     * @return array
     */
    public function getNewInWomenclothing_Kaftans();



     /**
     * Get Solr NewIn-Womenclothing Gowns
     *
     * @return array
     */
    public function getNewInWomenclothing_Gowns();




     /**
     * Get Solr NewIn-Womenclothing Pants
     *
     * @return array
     */
    public function getNewInWomenclothing_Pants();


    /**
     * Get Solr NewIn-Womenclothing Tunic & Kurtis
     *
     * @return array
     */
    public function getNewInWomenclothing_TunicsKurtis();


       /**
     * Get Solr NewIn-Womenclothing Capes
     *
     * @return array
     */
    public function getNewInWomenclothing_Capes();




       /**
     * Get Solr NewIn-Womenclothing Jumpsuits
     *
     * @return array
     */
    public function getNewInWomenclothing_Jumpsuits();


     /**
     * Get Solr NewIn-Womenclothing Kurtas
     *
     * @return array
     */
    public function getNewInWomenclothing_Kurtas();


      /**
     * Get Solr NewIn-Womenclothing Skirts
     *
     * @return array
     */
    public function getNewInWomenclothing_Skirts();


       /**
     * Get Solr NewIn-Womenclothing PalazzoSets
     *
     * @return array
     */
    public function getNewInWomenclothing_PalazzoSets();


       /**
     * Get Solr NewIn-Womenclothing Beach
     *
     * @return array
     */
    public function getNewInWomenclothing_Beach();



      
////////////////////////////////**New In Accessories**/////**** */
    
  /**
     * Get Solr NewIn-Accessories Bags
     *
     * @return array
     */
    public function getNewInAccessories_Bags();  

 /**
     * Get Solr NewIn-Accessories Shoes
     *
     * @return array
     */
    public function getNewInAccessories_Shoes();  

 /**
     * Get Solr NewIn-Accessories Belts
     *
     * @return array
     */
    public function getNewInAccessories_Belts();


 /**
     * Get Solr NewIn-Accessories Masks
     *
     * @return array
     */
    public function getNewInAccessories_Masks();
    
    
    ////////////////////////////////**New In Men**/////**** */

 /**
     * Get Solr NewIn-Men Kurtaset
     *
     * @return array
     */
    public function getNewInMen_KurtaSets();


 /**
     * Get Solr NewIn-Men Sherwanis
     *
     * @return array
     */
    public function getNewInMen_Sherwanis();


/**
     * Get Solr NewIn-Men Jackets
     *
     * @return array
     */
    public function getNewInMen_Jackets();


    /**
     * Get Solr NewIn-Men Men Accessories
     *
     * @return array
     */
    public function getNewInMen_MenAccessories();
    
    /**
     * Get Solr NewIn-Men Men Kurtas
     *
     * @return array
     */
    public function getNewInMen_Kurtas();

    /**
     * Get Solr NewIn-Men Men shirts
     *
     * @return array
     */
    public function getNewInMen_Shirts();

 
    
    /**
     * Get Solr NewIn-Men Men Bandis
     *
     * @return array
     */
    public function getNewInMen_Bandis();


    /**
     * Get Solr NewIn-Men Men Trousers
     *
     * @return array
     */
    public function getNewInMen_Trousers();


     /**
     * Get Solr NewIn- Jewellery Earrings
     *
     * @return array
     */
    public function getNewInJewellery_Earrings();  



     /**
     * Get Solr NewIn- Jewellery BanglesBracelets
     *
     * @return array
     */
    public function getNewInJewellery_BanglesBracelets(); 


     /**
     * Get Solr NewIn- Jewellery FineJewelry
     *
     * @return array
     */
    public function getNewInJewellery_FineJewelry(); 


         /**
     * Get Solr NewIn- Jewellery Hand Harness
     *
     * @return array
     */
    public function getNewInJewellery_HandHarness(); 


         /**
     * Get Solr NewIn- Jewellery Rings
     *
     * @return array
     */
    public function getNewInJewellery_Rings(); 


         /**
     * Get Solr NewIn- Jewellery Foot Harness
     *
     * @return array
     */
    public function getNewInJewellery_FootHarness(); 


         /**
     * Get Solr NewIn- Jewellery Foot Brooches
     *
     * @return array
     */
    public function getNewInJewellery_Brooches(); 

         /**
     * Get Solr NewIn- Jewellery Gift Boxes
     *
     * @return array
     */
    public function getNewInJewellery_GiftBoxes(); 

         /**
     * Get Solr NewIn- KidsWear KurtaSetsforBoys
     *
     * @return array
     */
    public function getNewInKidswear_KurtaSetsforBoys(); 


           /**
     * Get Solr NewIn- KidsWear Shararas
     *
     * @return array
     */
    public function getNewInKidswear_Shararas(); 


           /**
     * Get Solr NewIn- KidsWear Dresses
     *
     * @return array
     */
    public function getNewInKidswear_Dresses(); 
   
    
          /**
     * Get Solr NewIn- KidsWear KidsAccessories
     *
     * @return array
     */
    public function getNewInKidswear_KidsAccessories(); 

          /**
     * Get Solr NewIn- KidsWear Shirts
     *
     * @return array
     */
    public function getNewInKidswear_Shirts(); 


          /**
     * Get Solr NewIn- KidsWear Jackets
     *
     * @return array
     */
    public function getNewInKidswear_Jackets(); 


          /**
     * Get Solr NewIn- KidsWear Coordset
     *
     * @return array
     */
    public function getNewInKidswear_Coordset(); 

          /**
     * Get Solr NewIn- KidsWear Anarkalis
     *
     * @return array
     */
    public function getNewInKidswear_Anarkalis(); 

          /**
     * Get Solr NewIn- KidsWear Gowns
     *
     * @return array
     */
    public function getNewInKidswear_Gowns(); 


          /**
     * Get Solr NewIn- KidsWear Gowns
     *
     * @return array
     */
    public function getNewInKidswear_Bandhgalas(); 


          /**
     * Get Solr NewIn- KidsWear Dhotisets
     *
     * @return array
     */
    public function getNewInKidswear_Dhotisets(); 

          /**
     * Get Solr NewIn- KidsWear Jumpsuit
     *
     * @return array
     */
    public function getNewInKidswear_Jumpsuit(); 

          /**
     * Get Solr NewIn- KidsWear Sherwanis
     *
     * @return array
     */
    public function getNewInKidswear_Sherwanis(); 


          /**
     * Get Solr NewIn- KidsWear Tops
     *
     * @return array
     */
    public function getNewInKidswear_Tops(); 

          /**
     * Get Solr NewIn- KidsWear Skirts
     *
     * @return array
     */
    public function getNewInKidswear_Skirts(); 


          /**
     * Get Solr NewIn- KidsWear Sarees
     *
     * @return array
     */
    public function getNewInKidswear_Sarees(); 


          /**
     * Get Solr NewIn- Theme Contemprory
     *
     * @return array
     */
    public function getNewInTheme_Contemporary(); 


           /**
     * Get Solr NewIn- Theme Ethnic
     *
     * @return array
     */
    public function getNewInTheme_Ethnic(); 



           /**
     * Get Solr NewIn- Gender Men
     *
     * @return array
     */
    public function getNewInGender_Men(); 


        /**
     * Get Solr NewIn- Gender Women
     *
     * @return array
     */
    public function getNewInGender_Women(); 


 


        /**
     * Get Solr NewIn- Color
     *
     * @param string $colorName
     * @return array
     */
    public function geNewInColor($colorName); 

     /**
 * Get Solr NewIn- Color Black
 *
 * @return array
 */
public function getNewInColor_Black();   
     /**
 * Get Solr NewIn- Color Red
 *
 * @return array
 */
public function getNewInColor_Red(); 

/**
 * Get Solr NewIn- Color Blue
 *
 * @return array
 */
public function getNewInColor_Blue(); 

/**
 * Get Solr NewIn- Color Green
 *
 * @return array
 */
public function getNewInColor_Green(); 

/**
 * Get Solr NewIn- Color Yellow
 *
 * @return array
 */
public function getNewInColor_Yellow(); 

/**
 * Get Solr NewIn- Color White
 *
 * @return array
 */
public function getNewInColor_White(); 

/**
 * Get Solr NewIn- Color Pink
 *
 * @return array
 */
public function getNewInColor_Pink(); 

/**
 * Get Solr NewIn- Color Grey
 *
 * @return array
 */
public function getNewInColor_Grey(); 

/**
 * Get Solr NewIn- Color Brown
 *
 * @return array
 */
public function getNewInColor_Brown(); 

/**
 * Get Solr NewIn- Size XXSmall
 *
 * @return array
 */
public function getNewInSize_XXSmall(); 



/**
 * Get Solr NewIn- Size XSmall
 *
 * @return array
 */
public function getNewInSize_XSmall();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_Small();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_Medium();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_Large();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_XLarge();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_XXLarge();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_4XLarge();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_5XLarge();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_CustomMade();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_FreeSize();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize32();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize33();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize34();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize35();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize36();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize37();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize38();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize39();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize40();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize41();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize42();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize43();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize44();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize45();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize46();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize47();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize48();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_EuroSize49();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_BangleSize22();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_BangleSize24();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_BangleSize26();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_BangleSize28();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_6_12Months();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_1_2Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_2_3Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_3_4Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_4_5Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_5_6Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_6_7Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_7_8Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_8_9Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_9_10Years();


/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_10_11Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_11_12Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_12_13Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_13_14Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_14_15Years();

/**
 * Get Solr NewIn- Size Small
 *
 * @return array
 */
public function getNewInSize_15_16Years();

/////////////////*NEWIN Delivery*/////////////////////////

/**
 * Get Solr NewIn- Delivery
 *
 * @return array
 */
public function getNewInDelivery_Immediate();


/**
 * Get Solr NewIn- Delivery
 *
 * @return array
 */
public function getNewInDelivery_1_2Weeks();


/**
 * Get Solr NewIn- Delivery
 *
 * @return array
 */
public function getNewInDelivery_2_4Weeks();



/**
 * Get Solr NewIn- Delivery
 *
 * @return array
 */
public function getNewInDelivery_4_6Weeks();

/**
 * Get Solr NewIn- Delivery
 *
 * @return array
 */
public function getNewInDelivery_6_8Weeks();



/**
 * Get Solr NewIn- Delivery
 *
 * @return array
 */
public function getNewInDelivery_8Weeks();

/**
 * Get Solr Designers
 *
 * @return array
 */
public function getDesigners();


/**
 * Fetches products based on selected themes and category ID.
 *
 * @param string $themes Comma-separated themes.
 * @param string $categoryId Category ID to filter products.
 * @return array
 */
public function getThemeData(string $themes, string $categoryId);



/**
 * Delete item from cart via POST
 * @return array
 */
public function deleteCartItem();

/**
* Update quantity of cart item via POST
* @return array
*/
public function updateCartItemQty();







/**
* Fetch all country codes
* @return array
*/
public function getAllCountryCodes();




/**
* Fetch shipping rate based on country and region
* @param string $countryId
* @param int $regionId
* @param double $weight 
* @return array
*/
public function getShippingRate($countryId, $regionId, $weight);


/**
* Get cart details and total cart weight by customer ID.
*
* @param int $customerId
* @return mixed
*/
public function getCartDetailsByCustomerId($customerId);



  /**
     * Places an order for the currently logged-in customer.
     *
     * @param string $paymentMethodCode The code of the selected payment method.
     * @param mixed $billingAddress The billing address details.
     * @param string $paymentMethodNonce The payment token from Stripe (e.g., pm_xxxx)
     * @return int The created Order ID.
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    // ✅ ADD THE NEW PARAMETER TO THE METHOD SIGNATURE
    public function placeOrderForCustomer(string $paymentMethodCode, $billingAddress, string $paymentMethodNonce): int;



      /**
     * ✅ ADD THIS NEW METHOD DEFINITION
     *
     * Get complete order details for the authenticated customer.
     *
     * @param int $orderId
     * @return array
     * @throws NoSuchEntityException If the order does not exist.
     * @throws AuthorizationException If the customer is not authorized to view the order.
     */
    public function getOrderDetails(int $orderId);


      /**
     * ✅ START: ADD THIS NEW METHOD DEFINITION
     *
     * Sets a custom shipping price for the current customer's active cart.
     *
     * @api
     * @param float $shippingPrice The shipping price to be set, sent from the mobile app.
     * @return bool True on success.
     * @throws \Magento\Framework\Exception\NoSuchEntityException If the cart doesn't exist.
     * @throws \Magento\Framework\Exception\CouldNotSaveException If the price could not be saved.
     * @throws \Magento\Framework\Exception\LocalizedException For other errors like customer not logged in.
     */
    public function setShippingPrice(float $shippingPrice): bool;
    /** ✅ END: ADD THIS NEW METHOD DEFINITION */



/**
 * Get Megamenu Items
 *
 * @return array
 */
public function getMegamenuItems();


/**
 * Get Homepage CMS Content
 *
 * @return array
 */
public function getHomepageCmsContent();


   /**
     * ✅ ADD THIS NEW METHOD DEFINITION
     *
     * Fetches child categories from Solr based on a parent category name.
     *
     * @param string $parentCategoryName The name of the parent category (e.g., "Men").
     * @return array A list of child category names.
     */
    public function getChildCategories(string $parentCategoryName);

 /**
     * ✅ ADD THIS NEW METHOD DEFINITION
     *
     * Get active, direct sub-categories for a given parent category name.
     *
     * @param string $parentCategoryName The name of the parent category (e.g., "Men").
     * @return array A list of sub-category data.
     */
    public function getActiveSubCategoriesByName(string $parentCategoryName);
    


}
