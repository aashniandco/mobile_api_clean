<?php


namespace Aashni\MobileApi\Api;

/**
 * Interface for providing Solr product and filter data for listing pages.
 * @api
 */

interface SolrInterface
{


   /**
 * Get Megamenu Items
 *
 * @return array
 */
public function getMegamenuItems();

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
     * @param mixed $queryParams
     * @return array
     */
    public function getSolrSearch($queryParams);


    /**
 * Get Solr Designers
 *
 * @return array
 */
public function getDesigners();


   /**
     * Fetches designer products from Solr based on designer name.
     *
     * @param string $designerName Designer name to filter products.
     * @return array JSON response with designer products.
     */
    public function getDesignerData(string $designerName);


 /**
     * Update quantity of a GUEST cart item via POST.
     * Reads cart_id, item_id, and qty from the request parameters.
     * @return array
     */
    public function updateGuestCartItemQty();


  
      /**
     * Deletes an item from a specified guest cart.
     *
     * @param string $cartId The masked ID of the guest cart (e.g., "e5dJtIODe17hpy...")
     * @param int $itemId The ID of the item to be deleted from the cart.
     * @return bool
     * @throws \Magento\Framework\Exception\InputException If parameters are missing.
     * @throws \Magento\Framework\Exception\NoSuchEntityException If the cart or item cannot be found.
     * @throws \Magento\Framework\Exception\CouldNotSaveException If the item could not be removed.
     */
    public function deleteGuestItem($cartId, $itemId);


    

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
     * ✅ ADD THIS NEW METHOD DEFINITION
     *
     * Fetches child categories from Solr based on a parent category name.
     *
     * @param string $parentCategoryName The name of the parent category (e.g., "Men").
     * @return array A list of child category names.
     */
    public function getChildCategories(string $parentCategoryName);



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


}
