<?php

namespace AddTriplestore\Controller\Site;

require 'vendor/autoload.php';

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Http\Client;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use EasyRdf\Graph;
use Laminas\Form\FormInterface;
use Laminas\Router\RouteStackInterface;
use Laminas\Session\Container;

class IndexController extends AbstractActionController
{
    private $graphdbEndpoint = "http://localhost:7200/repositories/megalod/rdf-graphs/service";
    private $graphdbQueryEndpoint = "http://localhost:7200/repositories/megalod";
    private $baseDataGraphUri = "https://purl.org/megalod/";
    private $router;
    private $httpClient;

    private $uploadedFiles = null;

    private $excavationData = null;

    private $excavationIdentifier = "0"; // Default to the "0" graph
    
    private $currentProcessingItemSetId = null; // Track current item set being processed
        

    public function __construct(RouteStackInterface $router, Client $httpClient)
    {
        $this->router = $router;
        $this->httpClient = $httpClient;
    }



    // ================== ACTION METHODS ==================

    // MAIN ACTION INDEX ACTION

    /**
     * Site page default action
     *
     * Renders the main page of the AddTriplestore module in the site context.
     * Checks if the user is currently logged in and passes this status to the view along with the current site information.
     * @return \Laminas\View\Model\ViewModel The view model with site and login status
     */
    public function indexAction()
    {
        $site = $this->currentSite();
        $isLoggedIn = (bool) $this->identity();
        
        return new ViewModel([
            'site' => $site,
            'isLoggedIn' => $isLoggedIn
        ]);
    }

    // ================== AUTH ACTIONS ==================

    /**
     * Log out a user from the site
     *
     * Clears the user identity, site-specific user session data,
     * and destroys the session. Then redirects back to the site homepage
     *
     * @return \Laminas\Http\Response
     */
    public function logoutAction()
    {
        // Check if user is logged in
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
        
        // Clear user session data
        $session = new Container('site_user');
        $session->getManager()->getStorage()->clear();
        
        $sessionManager = Container::getDefaultManager();
        $sessionManager->destroy();
        
        $this->messenger()->addSuccess('Successfully logged out');
        return $this->redirect()->toRoute('site', ['site-slug' => $this->currentSite()->slug()]);
    }


    /**
     * Handle user signup for the site.
     * 
     * This action allows visitors to create a site-only user account.
     * If the user is already logged in, they will be redirected to the site's homepage.
     *
     * @return \Laminas\View\Model\ViewModel|\Laminas\Http\Response
     * Returns either a ViewModel with the signup form for GET requests or invalid POST submissions, or a redirect response on successful signup or if user is already logged in.
     */
    public function signupAction()
    {
        // If already logged in, redirect to main page
        if ($this->identity()) {
            return $this->redirect()->toRoute('site', ['site-slug' => $this->currentSite()->slug()]);
        }

        $form = $this->getSignupForm();
        $view = new ViewModel([
            'form' => $form,
            'site' => $this->currentSite()
        ]);
        $view->setTemplate('add-triplestore/site/index/signup');
        
        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            
            if ($form->isValid()) {
                $validatedData = $form->getData();
                
                // Check if passwords match
                if ($validatedData['password'] !== $validatedData['confirm_password']) {
                    $this->messenger()->addError('Passwords do not match');
                    return $view;
                }
                
                try {
                    $result = $this->createSiteOnlyUser($validatedData);
                    
                    if ($result['success']) {
                        $this->messenger()->addSuccess('Account created successfully! You can now log in.');
                        return $this->redirect()->toRoute('site/add-triplestore/login', ['site-slug' => $this->currentSite()->slug()]);
                    } else {
                        $this->messenger()->addError($result['error']);
                        return $view;
                    }
                    
                } catch (\Exception $e) {

                    $this->messenger()->addError('Error creating account: ' . $e->getMessage());
                    return $view;
                }
            } else {
                $this->messenger()->addError('Please correct the errors in the form');
            }
        }
        
        return $view;
    }



    /**
     * Handle user login for the Add Triplestore module.
     *
     * Processes login requests for admin and guest users. Redirects logged-in users
     * to the appropriate dashboard based on their role.
     *
     * @return \Laminas\View\Model\ViewModel|\Laminas\Http\Response
     */
    public function loginAction()
    {
        // If already logged in, redirect to main page
        if ($this->identity()) {
            $user = $this->identity();
            
            // Check if user is a guest/site-only user
            if ($user->getRole() === 'guest') {
                // return go to custom dashboard
                return $this->redirect()->toRoute('site/add-triplestore/dashboard', [
                    'site-slug' => $this->currentSite()->slug()
                ]);
            } else {
                // return go to admin dashboard omeka s.
                return $this->redirect()->toUrl('/admin');
            }
        }

        $form = $this->getServiceLocator()->get('FormElementManager')->get(\Omeka\Form\LoginForm::class);
        $view = new ViewModel([
            'form' => $form,
            'site' => $this->currentSite()
        ]);
        $view->setTemplate('add-triplestore/site/index/login');
        
        if ($this->getRequest()->isPost()) {

            $data = $this->params()->fromPost();
            
            //if csrf element exists in the form
            $csrfElement = $form->get('loginform_csrf');
            if ($csrfElement) {
                if (!isset($data['loginform_csrf'])) {
                    $data['loginform_csrf'] = $csrfElement->getValue();
                }
            }
            
            $form->setData($data);
           
            if (!$form->isValid()) {
                $errors = $form->getMessages();
            }
            if ($form->isValid()) {
                $validatedData = $form->getData();
                $sessionManager = Container::getDefaultManager();
                $sessionManager->regenerateId();
                
                // Use Omeka authentication
                $authService = $this->getServiceLocator()->get('Omeka\AuthenticationService');
                $adapter = $authService->getAdapter();
                $adapter->setIdentity($validatedData['email']);
                $adapter->setCredential($validatedData['password']);
                
             
                $result = $authService->authenticate();
                
                if ($result->isValid()) {
                    
                    $user = $authService->getIdentity();
                    
                    if ($user->getRole() === 'guest') {
                        return $this->redirect()->toRoute('site/add-triplestore/dashboard', [
                            'site-slug' => $this->currentSite()->slug()
                        ]);
                    } else {
                        return $this->redirect()->toUrl('/admin');
                    }
                } else {
                    $this->messenger()->addError('Email or password is invalid');
                }
            } else {
                $this->messenger()->addError('Email or password is invalid');
            }
        }
        
        return $view;
    }


    // ============= PAGE ACTION METHODS ==================

    /**
     * About Us page action
     *
     * Renders the About Us page of the AddTriplestore module.
     * @return \Laminas\View\Model\ViewModel The view model for the About Us page
     */
    public function aboutUsAction()
    {
        $view = new ViewModel();
        return $view;
    }

    /**
     * Shows the user dashboard for "guest-nonadministrative" users
     * 
     * Displays a dashboard for users with non administrative functions. Redirects admin users to the main admin dashboard.
     * This helps provide appropriate access levels and relevant information based on user roles.
     * 
     * @return \Laminas\View\Model\ViewModel|mixed Returns ViewModel for site users, redirects admins to admin dashboard
     */
    public function dashboardAction()
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) return $redirect;
        
        $user = $this->identity();
        
        // If this is an admin user, redirect to admin dashboard (omeka s)
        if ($this->userHasAdminAccess($user)) {
            return $this->redirect()->toUrl('/admin');
        }
        
        // Show dashboard
        $view = new ViewModel([
            'user' => $user,
            'site' => $this->currentSite(),
            'isLoggedIn' => true,
            'userRole' => $user->getRole()
        ]);
        $view->setTemplate('add-triplestore/site/index/user-dashboard');
        
        return $view;
    }

    /**
     * Action to provide SPARQL query interface via GraphDB.
     * 
     * This method sets up auto-login to a GraphDB instance with read-only credentials.
     * The user will be redirected to the GraphDB interface.
     * @return \Laminas\View\Model\ViewModel The view model containing GraphDB connection parameters
     */
    public function sparqlAction()
    {
        $graphdbUrl = 'http://localhost:7200/'; // current GraphDB instance URL
        
        // send to GraphDB with read-only credentials
        $view = new ViewModel();
        $view->setVariable('graphdbUrl', $graphdbUrl);
        $view->setVariable('username', 'read_only_user');
        $view->setVariable('password', ''); // No password for read_only_user was created in the example
        $view->setTemplate('add-triplestore/site/index/sparql-redirect');
        
        return $view;
    }

    /**
     * My Data Action Controller
     *
     * Shows items and item sets owned by the current user.
     *
     * @return \Laminas\View\Model\ViewModel|Response
     */
    public function myDataAction()
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) return $redirect;
        
        $user = $this->identity();
        
        // If this is an admin user, redirect to admin dashboard
        if ($this->userHasAdminAccess($user)) {
            return $this->redirect()->toUrl('/admin');
        }
        
        // Get the user's items and uploads
        $userId = $user->getId();
        $items = [];
        $itemSets = [];
        
        
        try {
            // Try to get items created by user
            $response = $this->api()->search('items', [
                'owner_id' => $userId,
                'sort_by' => 'created',
                'sort_order' => 'desc',
                'limit' => 50
            ]);
            $items = $response->getContent();
   
            
            // Get item sets created by user
            $itemSetResponse = $this->api()->search('item_sets', [
                'owner_id' => $userId,
                'sort_by' => 'created', 
                'sort_order' => 'desc',
                'limit' => 50
            ]);
            $itemSets = $itemSetResponse->getContent();

            
            // Check for items in item sets owned by this user
            foreach ($itemSets as $itemSet) {
                $itemSetItems = $this->api()->search('items', [
                    'item_set_id' => $itemSet->id(),
                    'limit' => 50
                ])->getContent();
                
                foreach ($itemSetItems as $item) {
                    $found = false;
                    foreach ($items as $existingItem) {
                        if ($existingItem->id() == $item->id()) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $items[] = $item;
   
                    }
                }
            }
            
            // Remove non-archaeological items like ctx item, etc...
            $filteredItems = [];
            foreach ($items as $item) {
                $isArrowhead = false;
                
                // Check resource class
                $resourceClass = $item->resourceClass();
                if ($resourceClass && strpos(strtolower($resourceClass->label()), 'arrowhead') !== false) {
                    $isArrowhead = true;
                }
                
                // Check for arrowhead-specific properties
                if (!$isArrowhead) {
                    $values = $item->values();
                    $arrowheadProperties = [
                        'Arrowhead Shape', 'Arrowhead Variant', 'Arrowhead Base',
                        'Chipping Mode', 'Chipping Direction', 'Chipping Shape'
                    ];
                    
                    foreach ($arrowheadProperties as $property) {
                        if (isset($values[$property]) && !empty($values[$property])) {
                            $isArrowhead = true;
                            break;
                        }
                    }
                }
                
                // Check title patterns
                if (!$isArrowhead) {
                    $title = $item->displayTitle();
                    if (strpos(strtolower($title), 'arrowhead') !== false || 
                        strpos($title, 'AH-') === 0 ||
                        preg_match('/^(?:item|archaeological item)\s+AH-/i', $title)) {
                        $isArrowhead = true;
                    }
                }
                
                // Exclude known non-arrowhead item types
                if (!$isArrowhead) {
                    $title = $item->displayTitle();
                    $nonArrowheadPatterns = [
                        '/^context/i', '/^ctx-/i', '/^square/i', 
                        '/^svu/i', '/^layer-/i', '/^stratigraphic/i',
                        '/^excav/i', '/^excavation/i', '/^location/i',
                        '/^archaeological encounter/i'
                    ];
                    
                    $isNonArrowhead = false;
                    foreach ($nonArrowheadPatterns as $pattern) {
                        if (preg_match($pattern, $title)) {
                            $isNonArrowhead = true;
                            break;
                        }
                    }
                    
                    $isArrowhead = !$isNonArrowhead;
                }
                
                if ($isArrowhead) {
                    $filteredItems[] = $item;
                }
            }

            $items = $filteredItems;
            
        } catch (\Exception $e) {
   
            $this->messenger()->addError('Failed to load your items: ' . $e->getMessage());
        }
        
   
        
        // Show user contributions page
        $view = new ViewModel([
            'user' => $user,
            'site' => $this->currentSite(),
            'items' => $items,
            'itemSets' => $itemSets, 
            'totalItems' => count($items),
            'totalItemSets' => count($itemSets), 
            'isLoggedIn' => true,
            'userRole' => $user->getRole()
        ]);
        $view->setTemplate('add-triplestore/site/index/my-data');
        
        return $view;
    }

/**
     * Show details for an item or item set.
     *
     * Gets the resource by id and type, and fetches related items for item sets.
     *
     * @return \Laminas\View\Model\ViewModel|\Laminas\Http\Response
     */
    public function viewDetailsAction()
    {
        $request = $this->getRequest();
        $requestedResourceType = $request->getQuery('type', 'item'); // Rename to avoid confusion
        $requestedId = (int) $request->getQuery('id'); // Ensure it's an integer
        error_log("View details action called for resource type: $requestedResourceType, ID: $requestedId", 3, OMEKA_PATH . '/logs/countt-add-triplestore.log');

        // if no ID is provided, redirect to search page
        if (!$requestedId) {
            return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
        }

        $resourceToDisplay = null; // This will be the actual object for the primary display
        $itemSetIdForLink = null; // This will store the correct item_set_id for links

        $properties = [];
        $relatedItems = [];
        $media = [];

        try {
            if ($requestedResourceType === 'item_set') {
                // When type is 'item_set', the requested ID IS the item_set_id
                $itemSetIdForLink = $requestedId;

                // First, try to fetch the item set itself as the primary resource to display
                $itemSetResource = $this->api()->read('item_sets', $requestedId)->getContent();

                if (!$itemSetResource) {
                    throw new \Exception("Item Set with ID {$requestedId} not found.");
                }

                $resourceToDisplay = $itemSetResource; // Default: display the item set itself

                // Optionally: Find a specific 'excavation' item within the item set for primary display
                // This part is for *displaying* a specific item, but $itemSetIdForLink remains the true item set ID.
                $excavationItems = $this->api()->search('items', [
                    'item_set_id' => $requestedId, // Use the correct requestedId here
                    'sort_by' => 'created',
                    'sort_order' => 'asc',
                    'limit' => 10
                ])->getContent();

                foreach ($excavationItems as $item) {
                    $resourceClass = $item->resourceClass();
                    if ($resourceClass && (
                        stripos($resourceClass->label(), 'excavation') !== false ||
                        stripos($item->displayTitle(), 'excavation') !== false
                    )) {
                        $resourceToDisplay = $item; // If found, display this item instead of the item set
                        break;
                    }
                }
                if (!$resourceToDisplay->resourceName() === 'items' && !empty($excavationItems)) {
                     // If resourceToDisplay is still the itemSet and there are items,
                     // perhaps default to the first item for display purposes if no specific excavation item was found.
                     // IMPORTANT: Ensure this doesn't overwrite $itemSetIdForLink
                     $resourceToDisplay = $excavationItems[0];
                }


                // Fetch all related items for this item set
                $searchParams1 = [
                    'item_set_id' => $requestedId, // Use the correct requestedId for related items
                    'sort_by' => 'created',
                    'sort_order' => 'desc',
                    'per_page' => 1000
                ];

                $response1 = $this->api()->search('items', $searchParams1);
                $relatedItems = $response1->getContent();
                error_log("Related items count: " . count($relatedItems), 3, OMEKA_PATH . '/logs/count-add-triplestore.log');
                // $totalResults1 = $response1->getTotalResults(); // Not used, can remove

                foreach ($relatedItems as $item) {
                    foreach ($item->media() as $m) {
                        if (strpos($m->mediaType(), 'image/') === 0) {
                            $media[] = $m;
                        }
                    }
                }

                // This block looks like a fallback if search('items', ['item_set_id' => $id]) fails.
                // It's less efficient as it iterates through ALL items.
                // It might indicate an underlying data model issue if the direct search fails.
                // Consider if this is truly needed or if the item_set_id search should always work.
                if (empty($relatedItems)) {
                    $allItemsResponse = $this->api()->search('items', ['per_page' => 1000]);
                    $allItems = $allItemsResponse->getContent();
                    error_log("All items count: " . count($allItems), 3, OMEKA_PATH . '/logs/count-add-triplestore.log');

                    foreach ($allItems as $item) {
                        $itemSets = $item->itemSets();
                        foreach ($itemSets as $itemSet) {
                            if ($itemSet->id() == $requestedId) { // Check against the original requestedId
                                $relatedItems[] = $item;
                            }
                        }
                    }
                }

            } else { // requestedResourceType is 'item' (Artifact)
                $resourceToDisplay = $this->api()->read('items', $requestedId)->getContent();

                if (!$resourceToDisplay) {
                    throw new \Exception("Item with ID {$requestedId} not found.");
                }

                // If viewing an item, we still need its item_set_id if it belongs to one
                // for any 'Add artifacts to this excavation' type links in the sidebar
                // (though that section will likely be hidden for 'item' view)
                $itemSets = $resourceToDisplay->itemSets();
                if (!empty($itemSets) && isset($itemSets[0]) && is_object($itemSets[0])) {
                $itemSetIdForLink = $itemSets[0]->id(); // Take the first item set ID this item belongs to
                } else {
                    $itemSetIdForLink = null; // Explicitly set to null if no item set is found
                }

                foreach ($resourceToDisplay->media() as $m) {
                    if (strpos($m->mediaType(), 'image/') === 0) {
                        $media[] = $m;
                    }
                }
            }

            if (!$resourceToDisplay) {
                throw new \Exception("Resource could not be loaded.");
            }

            $values = $resourceToDisplay->values(); // Get properties from the resource being displayed

            foreach ($values as $term => $propertyData) {
                try {
                    if (empty($propertyData)) {
                        continue;
                    }

                    $propertyLabel = $this->getHumanReadableLabel($term);
                    $propertyValues = [];

                    if (is_array($propertyData)) {
                        if (isset($propertyData['values']) && is_array($propertyData['values'])) {
                            $propertyValues = $propertyData['values'];
                            if (isset($propertyData['property']) && is_object($propertyData['property'])) {
                                if (method_exists($propertyData['property'], 'label')) {
                                    $propertyLabel = $propertyData['property']->label();
                                }
                            }
                        } else {
                            foreach ($propertyData as $item) {
                                if (is_object($item) && method_exists($item, 'value')) {
                                    $propertyValues[] = $item;
                                    if (empty($propertyValues) && method_exists($item, 'property')) {
                                        $prop = $item->property();
                                        if ($prop && method_exists($prop, 'label')) {
                                            $propertyLabel = $prop->label();
                                        }
                                    }
                                }
                            }
                        }
                    } else if (is_object($propertyData) && method_exists($propertyData, 'value')) {
                        $propertyValues = [$propertyData];
                        if (method_exists($propertyData, 'property')) {
                            $prop = $propertyData->property();
                            if ($prop && method_exists($prop, 'label')) {
                                $propertyLabel = $prop->label();
                            }
                        }
                    }

                    if (!empty($propertyValues)) {
                        $properties[] = [
                            'term' => $term,
                            'label' => $propertyLabel,
                            'values' => $propertyValues
                        ];
                    }

                } catch (\Exception $e) {
                    // Log the exception if needed, but continue processing other properties
                    error_log("Error processing property {$term}: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/countt-add-triplestore.log');
                    continue;
                }
            }

            usort($properties, function($a, $b) {
                if ($a['label'] === 'Title') return -1;
                if ($b['label'] === 'Title') return 1;
                return strcmp($a['label'], $b['label']);
            });

        } catch (\Exception $e) {
            $this->messenger()->addError('The requested resource could not be found: ' . $e->getMessage());
            return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
        }

        error_log("Final VIEW MODEL: resourceType: $requestedResourceType, requestedId: $requestedId, resourceToDisplay ID: " . ($resourceToDisplay ? $resourceToDisplay->id() : 'N/A') . ", itemSetIdForLink: " . ($itemSetIdForLink ?? 'N/A'), 3, OMEKA_PATH . '/logs/countt-add-triplestore.log');

        return new ViewModel([
            'resource' => $resourceToDisplay, // Pass the resource intended for display (could be item set or item)
            'resourceType' => $requestedResourceType, // The type requested by the URL
            'itemSetIdForLink' => $itemSetIdForLink, // THIS IS THE KEY: The correct item set ID for "Add Artifacts" links
            'properties' => $properties,
            'relatedItems' => $relatedItems,
            'media' => $media,
            'site' => $this->currentSite()
        ]);
    }

    // ================== FORMS  ==================

    /**
     * Process the collecting form submission.
     *
     * This action handles the submission of the collecting form, transforms the data into Arrowhead format and uploads it to GraphDB.
     *
     * @return \Laminas\Http\Response|\Laminas\View\Model\ViewModel
     */
    public function processCollectingFormAction()
    {
        $redirect = $this->requireLogin();
        if ($redirect) 
            return $redirect;

        $itemSetId = $this->params()->fromQuery('item_set_id');
        error_log("Processing collecting form for item set ID: $itemSetId", 3, OMEKA_PATH . '/logs/count-add-triplestore.log');
        $uploadType = $this->params()->fromQuery('upload_type', 'arrowhead');
        
        // Get data from the collecting form
        $formData = $this->params()->fromPost();
                
        $uploadedFiles = null;
        if (isset($_FILES['file']['54'])) {
            $uploadedFiles = $_FILES['file']['54'];
        }
        // transform the form data to Arrowhead data format
        $arrowheadData = $this->transformCollectingFormToArrowheadData($formData);

        
        if (!empty($arrowheadData)) {
            $ttlData = $this->processArrowheadFormData($arrowheadData, $itemSetId);

            $result = $this->uploadTtlDataWithMedia($ttlData, $itemSetId, $uploadedFiles);   
            
            
            // Redirect to excavation context with success message
            return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
                'site-slug' => $this->currentSite()->slug(),
            ], [
                'query' => [
                    'upload_type' => 'arrowhead',
                    'item_set_id' => $itemSetId,
                    'mode' => 'form',
                    'result' => $result,
                    'success' => '1'
                ]
            ]));
        }

        // If failed, redirect with error
        return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
            'site-slug' => $this->currentSite()->slug(),
        ], [
            'query' => [
                'upload_type' => 'arrowhead',
                'item_set_id' => $itemSetId,
                'mode' => 'form',
                'result' => 'Error: Could not process form data'
            ]
        ]));
    }

    // ================== UPLOAD ACTION ==================

    /**
     * upload handler for AddTriplestore.
     *
     * Handles arrowhead and excavation uploads via file or form, creates item sets as needed nand redirects or renders the appropriate view. Includes authentication and permission checks.
     *
     * @return mixed ViewModel or redirect response
     */

    public function uploadAction()
    {
        // Get POST data
        $redirect = $this->requireLogin();
        if ($redirect) return $redirect;

        $user = $this->identity();

        $postData = $this->params()->fromPost();

        // Check if is a continuous arrowhead upload (file upload or form submission)
        $uploadType = $this->params()->fromQuery('upload_type') ?: $this->params()->fromPost('upload_type');
        $itemSetId = $this->params()->fromQuery('item_set_id') ?: $this->params()->fromPost('item_set_id');
        $mode = $this->params()->fromQuery('mode', $this->params()->fromPost('mode', 'upload'));

        
        // Process arrowhead file upload
        if ($mode == 'file' && $uploadType == 'arrowhead' && $itemSetId) {
            $file = $this->params()->fromFiles('file');
            if ($file && !empty($file['tmp_name'])) {

                $result = $this->processFileUpload($this->getRequest(), $uploadType, $itemSetId);

                $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);

                if ($excavationId && strpos($result, 'successfully') !== false) {
                    $result = "Arrowhead was successfully added to excavation $excavationId (Item Set #$itemSetId). You can upload another or click Exit when done.";
                }
                
                // Redirect back to upload page to enable continuous uploads
                $url = $this->url()->fromRoute('site/add-triplestore/upload', [
                    'site-slug' => $this->currentSite()->slug(),
                ], [
                    'query' => [
                        'upload_type' => 'arrowhead',
                        'item_set_id' => $itemSetId,
                        'mode' => 'file',
                        'result' => $result
                    ]
                ]);
                return $this->redirect()->toUrl($url);
            }
            
            $view = new ViewModel([
                'itemSetId' => $itemSetId,
                'uploadType' => $uploadType,
                'result' => $this->params()->fromQuery('result')
            ]);
            $view->setTemplate('add-triplestore/site/index/upload-arrowhead');
            return $view;
        }

        // arrowhead form processing
        if ($mode == 'form' && $uploadType == 'arrowhead') {

            $formData = $this->params()->fromPost();
            
            $success = $this->params()->fromQuery('success', false);
            
            if (!empty($formData) && empty($success)) {

                // If this is a form submission, process the data
                $ttlData = $this->processArrowheadFormData($formData, $itemSetId);

                $result = $this->uploadTtlData($ttlData, $itemSetId) ?? 'Unknown error occurred during upload';
                
                // Redirect to success page
                $url = $this->url()->fromRoute('site/add-triplestore/upload', [
                    'site-slug' => $this->currentSite()->slug(),
                ], [
                    'query' => [
                        'upload_type' => 'arrowhead',
                        'item_set_id' => $itemSetId,
                        'mode' => 'form',
                        'result' => $result,
                        'success' => '1'
                    ]
                ]);
                
                return $this->redirect()->toUrl($url);
            } else {
                
                $view = new ViewModel([
                    'itemSetId' => $itemSetId,
                    'uploadType' => $uploadType,
                    'result' => $this->params()->fromQuery('result', ''),
                    'success' => $success
                ]);
                $view->setTemplate('add-triplestore/site/index/upload-arrowhead');
                return $view;
            }
        }

        // Process the excavation form submission
        if ($uploadType == 'excavation' && !isset($_FILES['file'])) {

            $formData = $this->params()->fromPost();
                        
            // Transform collecting form data to excavation format
            $excavationData = $this->transformCollectingFormToExcavationData($formData);
            
            if (!empty($excavationData)) {

                $excavationIdentifier = $excavationData['excavation_id'] ?? null;
                
                
                // ttlData processing
                $ttlData = $this->processExcavationFormData($excavationData, $excavationIdentifier);
                $itemSetData = $this->createExcavationItemSetData($excavationIdentifier, $excavationData);
                
                try {
                    // Create the item set
                    $response = $this->api()->create('item_sets', $itemSetData);
                    if ($response) {
                        $newItemSet = $response->getContent();
                        $itemSetId = $newItemSet->id();
                        
                        // Store the mapping between item set and excavation
                        $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
                        
                        // Upload TTL data to triplestore
                        $result = $this->uploadTtlData($ttlData, $itemSetId);
                        
                        return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
                            'site-slug' => $this->currentSite()->slug(),
                        ], [
                            'query' => [
                                'upload_type' => 'arrowhead',
                                'item_set_id' => $itemSetId,
                                'mode' => 'file',
                                'result' => $result
                            ]
                        ]));
                    }
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'permission') !== false) {
                        $this->messenger()->addError('You do not have permission to create excavations. Please contact an administrator.');
                    } else {
                        $this->messenger()->addError('Failed to create excavation: ' . $e->getMessage());
                    }
                    if (!$this->canUserCreateResource('ItemSet')) {
                        $this->messenger()->addError('You do not have permission to create excavations.');
                        return $this->redirect()->toRoute('site/add-triplestore/dashboard', [
                            'site-slug' => $this->currentSite()->slug()
                        ]);
                    }
                    return $this->redirect()->toRoute('site/add-triplestore/dashboard', [
                        'site-slug' => $this->currentSite()->slug()
                    ]);
                }
            }
            
            // If transformation failed rediect with error
            return $this->redirect()->toUrl($this->url()->fromRoute('site', [
                'site-slug' => $this->currentSite()->slug()
            ], [
                'query' => [
                    'result' => 'Error: Could not process excavation form data'
                ]
            ]));
        }
        
        // direct file uploads
        else if (isset($_FILES['file']) && !empty($_FILES['file']['tmp_name'])) {
            
            $result = $this->processFileUpload($this->getRequest(), $uploadType, $itemSetId);
            // If error, redirect back to upload page with error message
            if (strpos($result, 'Error') !== false || strpos($result, 'Validation Error') !== false || strpos($result, 'Failed') !== false) {
                $url = $this->url()->fromRoute('site/add-triplestore/upload', [
                    'site-slug' => $this->currentSite()->slug(),
                ], [
                    'query' => [
                        'upload_type' => $uploadType,
                        'item_set_id' => $itemSetId,
                        'mode' => $mode,
                        'result' => $result
                    ]
                ]);
                return $this->redirect()->toUrl($url);
            }
            if ($uploadType == 'excavation') {

                preg_match('/Excavation ([A-Za-z0-9-]+)/', $result, $matches);
                $excavationIdentifier = isset($matches[1]) ? $matches[1] : null;

                if ($excavationIdentifier) {
                    // Get the item set id 
                    if (strpos($result, 'Item Set #') !== false) {
                        preg_match('/Item Set #(\d+)/', $result, $matches);
                        $itemSetId = isset($matches[1]) ? $matches[1] : null;
                    }
                    
                    if ($itemSetId) {

                        // Redirect to the arrowhead upload form with the excavation context
                        return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
                            'site-slug' => $this->currentSite()->slug(),
                        ], [
                            'query' => [
                                'upload_type' => 'arrowhead',
                                'item_set_id' => $itemSetId,
                                'mode' => 'file',
                                'result' => $result
                            ]
                        ]));
                    }
                }
            }
            
            // For excavation file uploads
            if ($uploadType == 'excavation' && strpos($result, 'successfully') !== false) {
                // extract item set id from the result
                preg_match('/Item Set #(\d+)/', $result, $matches);
                $newItemSetId = isset($matches[1]) ? $matches[1] : null;
                
                if ($newItemSetId) {
                    return $this->redirect()->toUrl($this->url()->fromRoute('site/add-triplestore/upload', [
                        'site-slug' => $this->currentSite()->slug(),
                    ], [
                        'query' => [
                            'upload_type' => 'arrowhead',
                            'item_set_id' => $newItemSetId,
                            'mode' => 'file',
                            'result' => $result
                        ]
                    ]));
                }
            }
            
            // redirect to the index page with the result
            return $this->redirect()->toUrl($this->url()->fromRoute('site', [
                'site-slug' => $this->currentSite()->slug()
            ], [
                'query' => [
                    'result' => $result,
                    'item_set_id' => $itemSetId
                ]
            ]));
        }
        
        // Default if no specific upload type was recognized
        return $this->redirect()->toUrl($this->url()->fromRoute('site', ['site-slug' => $this->currentSite()->slug()]));
    }


    // ================== DOWNLOAD METHODS ==================

    /**
     * Download template for arrowhead or excavation data.
     *
     * This action allows users to download a template file in either Turtle (TTL) or XML format for each ttype of data.
     *
     * @return \Laminas\Http\Response
     */
    public function downloadTemplateAction()
    {
        // Get template type and format
        $templateType = $this->params()->fromQuery('template', 'arrowhead'); // 'arrowhead' or 'excavation'
        $format = $this->params()->fromQuery('format', 'ttl'); // 'ttl' or 'xml'

        // Validate inupt
        $allowedTemplates = ['arrowhead', 'excavation'];
        $allowedFormats = ['ttl', 'xml'];

        if (!in_array($templateType, $allowedTemplates) || !in_array($format, $allowedFormats)) {
            $this->messenger()->addError('Invalid template or format requested.');
            return $this->redirect()->toRoute('site/add-triplestore/upload', [
                'site-slug' => $this->currentSite()->slug()
            ]);
        }

        $filename = "{$templateType}.{$format}";
        $filePath = OMEKA_PATH . '/modules/AddTriplestore/asset/templates/' . $filename;

        if (!file_exists($filePath)) {
            $this->messenger()->addError('Template file not found.');
            return $this->redirect()->toRoute('site/add-triplestore/upload', [
                'site-slug' => $this->currentSite()->slug()
            ]);
        }

        // Set content type
        $contentType = $format === 'xml' ? 'application/xml' : 'text/turtle';

        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', $contentType);
        $response->getHeaders()->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setContent(file_get_contents($filePath));
        
        return $response;
    }

    /**
     * Download TTL data for an item or item set.
     * @return \Laminas\Http\Response|\Laminas\Stdlib\ResponseInterface
     */
    public function downloadTtlAction()
    {
        $id = $this->params()->fromQuery('id');
        $type = $this->params()->fromQuery('type', 'item');
        
        if (empty($id)) {
            return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
        }
        
        try {
            // Get the resource to extract
            $resourceType = $type === 'item_set' ? 'item_sets' : 'items';
            $resource = $this->api()->read($resourceType, $id)->getContent();
            
            if ($type === 'item_set') {
                // For item sets, query the GraphDB for TTL data
                $ttlData = $this->queryCompleteExcavationFromGraphDB($id, $resource);
            } else {
                // For items, query the GraphDB for TTL data
                $ttlData = $this->queryItemFromGraphDB($resource, $id);
            }
            
            if (empty($ttlData)) {
                $this->messenger()->addError('No TTL data found for this resource.');
                return $this->redirect()->toRoute('site/add-triplestore/view-details', 
                    ['site-slug' => $this->currentSite()->slug()],
                    ['query' => ['id' => $id, 'type' => $type]]
                );
            }

            $filename = $this->sanitizeFilename($resource->displayTitle());
            
            $response = $this->getResponse();
            $response->getHeaders()->addHeaderLine('Content-Type', 'text/turtle; charset=UTF-8');
            $response->getHeaders()->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '.ttl"');
            $response->setContent($ttlData);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->messenger()->addError('Error generating TTL data: ' . $e->getMessage());
            return $this->redirect()->toRoute('site/add-triplestore/view-details', 
                ['site-slug' => $this->currentSite()->slug()],
                ['query' => ['id' => $id, 'type' => $type]]
            );
        }
    }






    // ================== SEARCH ACTIONS ==================

/**
     * This method handles the search functionality for items and item sets.
     * @return ViewModel
     */
    public function searchAction()
    {
        // Check if user is logged in
        $request = $this->getRequest();
        $searchQuery = $request->getQuery('query', '');
        $searchType = $request->getQuery('type', 'all'); // 'items', 'item_sets', or 'all'
        $page = $request->getQuery('page', 1);
        $perPage = 20;
        
        // Excavation filters
        $filterArchaeologist = $request->getQuery('archaeologist', '');
        $filterOrcid = $request->getQuery('orcid', '');
        $filterCountry = $request->getQuery('country', '');
        $filterDistrict = $request->getQuery('district', '');
        $filterParish = $request->getQuery('parish', '');
        
        // Basic arrowhead filters
        $filterShape = $request->getQuery('shape', '');
        $filterVariant = $request->getQuery('variant', '');
        $filterMaterial = $request->getQuery('material', '');
        $filterElongation = $request->getQuery('elongation', '');
        
        // morphological filters
        $filterThickness = $request->getQuery('thickness', '');
        $filterBase = $request->getQuery('base', '');
        $filterCondition = $request->getQuery('condition', '');
        
        // Chipping filters
        $filterChippingMode = $request->getQuery('chippingMode', '');
        $filterChippingDirection = $request->getQuery('chippingDirection', '');
        $filterChippingDelineation = $request->getQuery('chippingDelineation', '');
        $filterChippingShape = $request->getQuery('chippingShape', '');
        $filterChippingAmplitude = $request->getQuery('chippingAmplitude', '');
        
        // Size and weight filters
        $minHeight = $request->getQuery('minHeight', '');
        $maxHeight = $request->getQuery('maxHeight', '');
        $minWidth = $request->getQuery('minWidth', '');
        $maxWidth = $request->getQuery('maxWidth', '');
        $minThickness = $request->getQuery('minThickness', '');
        $maxThickness = $request->getQuery('maxThickness', '');
        $minWeight = $request->getQuery('minWeight', '');
        $maxWeight = $request->getQuery('maxWeight', '');
        
        $results = [];
        $totalItems = 0;
        $totalItemSets = 0;
        
        // retireve filter options from GraphDB
        $archaeologistOptions = $this->getArchaeologistOptions();
        $countryOptions = $this->getCountryOptions();
        $districtOptions = $this->getDistrictOptions();
        $parishOptions = $this->getParishOptions();
        
        // Prepare the results array with filter options
        $hasFilters = $filterShape || $filterVariant || $filterMaterial || $filterElongation || 
                $filterThickness || $filterBase || $filterCondition || $filterChippingMode || 
                $filterChippingDirection || $filterChippingDelineation || $filterChippingShape || 
                $filterChippingAmplitude || $minHeight || $maxHeight || $minWidth || $maxWidth || 
                $minThickness || $maxThickness || $minWeight || $maxWeight ||
                $filterArchaeologist || $filterOrcid || $filterCountry || $filterDistrict || $filterParish;

        if ($searchQuery || $hasFilters) {
            if ($searchType === 'all' || $searchType === 'item_sets') {
                $itemSetQuery = [];
        
                // search query
                if ($searchQuery) {
                    $itemSetQuery['fulltext_search'] = $searchQuery;
                }
        
                // If we have excavation filters, search excavation items first
                if ($filterArchaeologist || $filterOrcid || $filterCountry || $filterDistrict || $filterParish) {
                    // Search for excavation items
                    
                    $excavationItemQuery = [];
                    $propertyFilters = [];
                    
                    if ($filterArchaeologist) {
                        $propertyFilters[] = [
                            'property' => 7665, // Person in Charge property ID
                            'type' => 'in',
                            'text' => $filterArchaeologist
                        ];
                    }
                    
                    if ($filterOrcid) {
                        $propertyFilters[] = [
                            'property' => 176, // ORCID property ID
                            'type' => 'eq',
                            'text' => $filterOrcid
                        ];
                    }
                    
                    if ($filterCountry) {
                        $propertyFilters[] = [
                            'property' => 1402, // Country property ID
                            'type' => 'eq',
                            'text' => $filterCountry
                        ];
                    }
                    
                    if ($filterDistrict) {
                        $propertyFilters[] = [
                            'property' => 1555, // district property ID
                            'type' => 'eq',
                            'text' => $filterDistrict
                        ];
                    }
                    
                    if ($filterParish) {
                        $propertyFilters[] = [
                            'property' => 1681, // parish property ID
                            'type' => 'eq',
                            'text' => $filterParish
                        ];
                    }
                    
                    if (!empty($propertyFilters)) {
                        $excavationItemQuery['property'] = $propertyFilters;
                    }
                    
                    // filter by title pattern to only get excavation items
                    $excavationItemQuery['fulltext_search'] = 'Excavation';
                                        
                    // Search for excavation items
                    $excavationItemsResponse = $this->api()->search('items', $excavationItemQuery);
                    $excavationItems = $excavationItemsResponse->getContent();
                                        
                    // Extract item set id from the excavation items
                    $itemSetIds = [];
                    foreach ($excavationItems as $item) {
                        $itemSets = $item->itemSets();
                        foreach ($itemSets as $itemSet) {
                            $itemSetIds[] = $itemSet->id();
                        }
                    }
                    
                    // Remove duplicates
                    $itemSetIds = array_unique($itemSetIds);
                    
                    
                    if (!empty($itemSetIds)) {

                        $itemSetQuery['id'] = $itemSetIds;
                        
                        // add the search query if provided
                        if ($searchQuery) {
                            unset($itemSetQuery['fulltext_search']);
                        }
                                                
                        $itemSetsResponse = $this->api()->search('item_sets', $itemSetQuery);
                        $results['item_sets'] = $itemSetsResponse->getContent();
                        $totalItemSets = $itemSetsResponse->getTotalResults();
                    } else {
                        $results['item_sets'] = [];
                        $totalItemSets = 0;
                    }
                } else {
                    // search item sets
                    $itemSetsResponse = $this->api()->search('item_sets', $itemSetQuery);
                    $results['item_sets'] = $itemSetsResponse->getContent();
                    $totalItemSets = $itemSetsResponse->getTotalResults();
                }
            }
            if ($searchType === 'all' || $searchType === 'items') {
                $itemQuery = [];
                
                //  search query
                if ($searchQuery) {
                    if ($searchQuery === 'arrowhead') {
                        $itemQuery['fulltext_search'] = 'arrowhead* OR "archaeological item*"';
                    } else {
                        $itemQuery['fulltext_search'] = $searchQuery;
                    }
                }
                
                // Apply arrowhead filters
                $propertyFilters = [];
                
                if ($filterShape) {
                    $propertyFilters[] = [
                        'property' => 7651,  // Arrowhead Shape property ID
                        'type' => 'eq',
                        'text' => $filterShape
                    ];
                }
                
                if ($filterVariant) {
                    $propertyFilters[] = [
                        'property' => 7652,  // Arrowhead Variant property ID
                        'type' => 'eq',
                        'text' => $filterVariant
                    ];
                }
                
                if ($filterMaterial) {
                    $propertyFilters[] = [
                        'property' => 4633,  // Material property ID
                        'type' => 'eq',
                        'text' => $filterMaterial
                    ];
                }
                
                if ($filterElongation) {
                    $propertyFilters[] = [
                        'property' => 7676,  // Elongation Index property ID
                        'type' => 'eq',
                        'text' => $filterElongation
                    ];
                }
                
                if ($filterThickness) {
                    $propertyFilters[] = [
                        'property' => 7677,  // Thickness Index property ID
                        'type' => 'eq',
                        'text' => $filterThickness
                    ];
                }
                
                if ($filterBase) {
                    $propertyFilters[] = [
                        'property' => 7653,  // Base Type property ID
                        'type' => 'eq',
                        'text' => $filterBase
                    ];
                }
                
                if ($filterCondition !== '') {
                    $propertyFilters[] = [
                        'property' => 476,  // Condition State property ID
                        'type' => 'eq',
                        'text' => $filterCondition
                    ];
                }
                
                if ($filterChippingMode) {
                    $propertyFilters[] = [
                        'property' => 7656,  // Chipping Mode property ID
                        'type' => 'eq',
                        'text' => $filterChippingMode
                    ];
                }
                
                if ($filterChippingDirection) {
                    $propertyFilters[] = [
                        'property' => 7658,  // Chipping Direction property ID
                        'type' => 'eq',
                        'text' => $filterChippingDirection
                    ];
                }
                
                if ($filterChippingDelineation) {
                    $propertyFilters[] = [
                        'property' => 7660,  // Chipping Delineation property ID
                        'type' => 'eq',
                        'text' => $filterChippingDelineation
                    ];
                }
                
                if ($filterChippingShape) {
                    $propertyFilters[] = [
                        'property' => 7661,  // Chipping Shape property ID
                        'type' => 'eq',
                        'text' => $filterChippingShape
                    ];
                }
                
                if ($filterChippingAmplitude !== '') {
                    $propertyFilters[] = [
                        'property' => 7657,  // Chipping Amplitude property ID
                        'type' => 'eq',
                        'text' => $filterChippingAmplitude
                    ];
                }
                
                if ($minHeight) {
                    $propertyFilters[] = [
                        'property' => 5616,  // Height property ID
                        'type' => 'gte',
                        'text' => $minHeight
                    ];
                }
                
                if ($maxHeight) {
                    $propertyFilters[] = [
                        'property' => 5616,  // Height property ID
                        'type' => 'lte',
                        'text' => $maxHeight
                    ];
                }
                
                if ($minWidth) {
                    $propertyFilters[] = [
                        'property' => 5688,  // Width property ID
                        'type' => 'gte',
                        'text' => $minWidth
                    ];
                }
                
                if ($maxWidth) {
                    $propertyFilters[] = [
                        'property' => 5688,  // Width property ID
                        'type' => 'lte',
                        'text' => $maxWidth
                    ];
                }
                
                if ($minThickness) {
                    $propertyFilters[] = [
                        'property' => 7244,  // Thickness property ID
                        'type' => 'gte',
                        'text' => $minThickness
                    ];
                }
                
                if ($maxThickness) {
                    $propertyFilters[] = [
                        'property' => 7244,  // Thickness property ID
                        'type' => 'lte',
                        'text' => $maxThickness
                    ];
                }
                
                if ($minWeight) {
                    $propertyFilters[] = [
                        'property' => 5779,  // Weight property ID
                        'type' => 'gte',
                        'text' => $minWeight
                    ];
                }
                
                if ($maxWeight) {
                    $propertyFilters[] = [
                        'property' => 5779,  // Weight property ID
                        'type' => 'lte',
                        'text' => $maxWeight
                    ];
                }
                
                if (!empty($propertyFilters)) {
                    $itemQuery['property'] = $propertyFilters;
                }
                
                // Execute the items search
                $itemsResponse = $this->api()->search('items', $itemQuery);
                $results['items'] = $itemsResponse->getContent();
                $totalItems = $itemsResponse->getTotalResults();
            }
        }
        
        $totalResults = $totalItems + $totalItemSets;
        
        return new ViewModel([
            'site' => $this->currentSite(),
            'searchQuery' => $searchQuery,
            'searchType' => $searchType,
            'results' => $results,
            'totalResults' => $totalResults,
            'totalItems' => $totalItems,
            'totalItemSets' => $totalItemSets,
            'archaeologistOptions' => $archaeologistOptions,
            'countryOptions' => $countryOptions,
            'districtOptions' => $districtOptions,
            'parishOptions' => $parishOptions,
            'filterArchaeologist' => $filterArchaeologist,
            'filterOrcid' => $filterOrcid,
            'filterCountry' => $filterCountry,
            'filterDistrict' => $filterDistrict,
            'filterParish' => $filterParish,
        ]);
    }

    // ================== PREvent METHODS ==================

    /**
     * Pre-dispatch access control.
     * Calls parent preDispatch if available and prevents site-only users from accessing admin areas.
     */
    public function preDispatch(\Laminas\Mvc\MvcEvent $e)
    {
        // Call parent preDispatch if it exists
        if (method_exists(get_parent_class(), 'preDispatch')) {
            parent::preDispatch($e);
        }
        
        $this->preventAdminAccess($e);
    }

    /**
     * Prevent site-only users from accessing admin areas.
     *
     * Redirects users with the 'guest' role away from admin sections.
     *
     * @param \Laminas\Mvc\MvcEvent $e
     */
    private function preventAdminAccess(\Laminas\Mvc\MvcEvent $e)
    {
        $request = $e->getRequest();
        $uri = $request->getUri();
        $path = $uri->getPath();
        
        // Check if user is trying to access admin areas
        $adminPaths = ['/admin', '/api', '/application'];
        
        $isAdminPath = false;
        foreach ($adminPaths as $adminPath) {
            if (strpos($path, $adminPath) === 0) {
                $isAdminPath = true;
                break;
            }
        }
        
        if ($isAdminPath && $this->identity()) {
            $user = $this->identity();
            
            // If user is a site only user redirct away from admin
            if ($user->getRole() === 'guest') {
                $this->messenger()->addError('Access denied. You do not have permission to access administrative areas.');
                
                // Redirect to allowed site
                $session = new Container('site_user');
                $siteSlug = $session->allowedSite ?: $this->currentSite()->slug();
                
                $response = $e->getResponse();
                $response->getHeaders()->addHeaderLine('Location', $this->url()->fromRoute('site', ['site-slug' => $siteSlug]));
                $response->setStatusCode(302);
                return $response;
            }
        }
    }
 


    /**
     * Get the application service manager.
     *
     * @return \Laminas\ServiceManager\ServiceManager
     */
    private function getServiceLocator()
    {
        $serviceManager = $this->getEvent()->getApplication()->getServiceManager();
        return $serviceManager;
    }


    // ================== USER MANAGEMENT METHODS ==================



    /**
     * Create a site-only user with 'guest' role.
     *
     * @param array $userData ['email', 'name', 'password']
     * @return array ['success' => bool, 'error' => string|null]
     */
    private function createSiteOnlyUser($userData)
    {
        try {
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            
            // Check if user exists
            $checkSql = "SELECT id FROM user WHERE email = ?";
            $stmt = $connection->prepare($checkSql);
            $stmt->execute([$userData['email']]);
            
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'A user with this email already exists'];
            }
            
            // Hash password using Omeka method 
            $hashedPassword = $this->getServiceLocator()
                ->get('Omeka\EntityManager')
                ->getRepository('Omeka\Entity\User')
                ->hashPassword($userData['password']);
            
            // Insert user with 'guest' role (no admin access)
            $insertSql = "INSERT INTO user (email, name, role, is_active, password_hash, created) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $connection->prepare($insertSql);
            
            $result = $stmt->execute([
                $userData['email'],
                $userData['name'],
                'guest', // role
                1, // is_active
                $hashedPassword,
                date('Y-m-d H:i:s')
            ]);
            
            if ($result) {
                $userId = $connection->lastInsertId();
                
                $this->addUserToSite($userId, $this->currentSite()->id());
                
    
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Failed to create user account'];
            }
            
        } catch (\Exception $e) {
    
    
            
            if (strpos($e->getMessage(), 'email') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                return ['success' => false, 'error' => 'A user with this email already exists'];
            } else {
                return ['success' => false, 'error' => 'Failed to create account: ' . $e->getMessage()];
            }
        }
    }

    /**
     * Adds a user to the current site
     *
     * @param int $userId The ID of the user to add
     * @param int $siteId The ID of the site to which the user is being added
     */
    private function addUserToSite($userId, $siteId)
    {
        try {
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            
            // Add user to site_permission table with 'viewer' role
            $insertSql = "INSERT INTO site_permission (site_id, user_id, role) VALUES (?, ?, ?)";
            $stmt = $connection->prepare($insertSql);
            $stmt->execute([$siteId, $userId, 'viewer']);
            
    
            
        } catch (\Exception $e) {
        }
    }


    /**
     * Checks if the current user has admin access to the dashboard.
     * If the user is not logged in, it returns false.
     *
     * @param \Omeka\Entity\User|null $user The user entity or null if not logged in
     * @return bool True if the user has admin access, false otherwise
     */
    private function userHasAdminAccess($user)
    {
        if (!$user) {
            return false;
        }
        
        $role = $user->getRole();
        
        $adminRoles = ['global_admin', 'site_admin', 'editor', 'reviewer', 'author'];
        
        return in_array($role, $adminRoles);
    }







    /**
     * Checks if the current user is logged in.
     * If not, it redirects to the login page with an error message.
     *
     * @return \Laminas\Http\Response|null Returns null if the user is logged in, otherwise redirects to login
     */
    private function requireLogin()
    {
        if (!$this->identity()) {
            $this->messenger()->addError('You must log in to access this page');
            return $this->redirect()->toRoute('site/add-triplestore/login', [
                'site-slug' => $this->currentSite()->slug()
            ]);
        }
        
        // Log the current user for debugging
        $user = $this->identity();
    
        
        // Allow guest users to proceed
        return null;
    }





    /**
     * Get the signup form for creating a new site-only user.
     *
     * @return \Laminas\Form\Form
     */
    private function getSignupForm()
    {
        $form = new \Laminas\Form\Form('signup');
        $form->setAttribute('method', 'post');
        
        $form->add([
            'name' => 'name',
            'type' => 'text',
            'options' => [
                'label' => 'Full Name'
            ],
            'attributes' => [
                'required' => true,
                'class' => 'form-control'
            ]
        ]);
        
        $form->add([
            'name' => 'email',
            'type' => 'email',
            'options' => [
                'label' => 'Email'
            ],
            'attributes' => [
                'required' => true,
                'class' => 'form-control'
            ]
        ]);
        
        $form->add([
            'name' => 'password',
            'type' => 'password',
            'options' => [
                'label' => 'Password'
            ],
            'attributes' => [
                'required' => true,
                'class' => 'form-control'
            ]
        ]);
        
        $form->add([
            'name' => 'confirm_password',
            'type' => 'password',
            'options' => [
                'label' => 'Confirm Password'
            ],
            'attributes' => [
                'required' => true,
                'class' => 'form-control'
            ]
        ]);
        
        $form->add([
            'name' => 'submit',
            'type' => 'submit',
            'attributes' => [
                'value' => 'Create Account',
                'class' => 'btn btn-primary'
            ]
        ]);
        
        return $form;
    }


    // ================== UTILITY METHODS ==================

    

    /**
     * Returns a string containing the necessary RDF prefixes for TTL files.
     * This method is used to generate the header for TTL files to ensure they are properly formatted with the required namespaces.
     * @return string A string of RDF prefixes in Turtle format.
     */
    private function getTtlPrefixes() 
    {
        return "@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .\n" .
            "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n" .
            "@prefix sh: <http://www.w3.org/ns/shacl#> .\n" .
            "@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .\n" .
            "@prefix skos: <http://www.w3.org/2004/02/skos/core#> .\n" .
            "@prefix dct: <http://purl.org/dc/terms/> .\n" .
            "@prefix foaf: <http://xmlns.com/foaf/0.1/> .\n" .
            "@prefix dbo: <http://dbpedia.org/ontology/> .\n" .
            "@prefix crm: <http://www.cidoc-crm.org/cidoc-crm/> .\n" .
            "@prefix crmsci: <http://cidoc-crm.org/extensions/crmsci/> .\n" .
            "@prefix crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/> .\n" .
            "@prefix edm: <http://www.europeana.eu/schemas/edm/> .\n" .
            "@prefix geo: <http://www.w3.org/2003/01/geo/wgs84_pos#> .\n" .
            "@prefix time: <http://www.w3.org/2006/time#> .\n" .
            "@prefix schema: <http://schema.org/> .\n" .
            "@prefix ah: <https://purl.org/megalod/ms/ah/> .\n" .
            "@prefix excav: <https://purl.org/megalod/ms/excavation/> .\n" .
            "@prefix dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#> .\n";
    }
    
 
/**
 * Sanitize a string for use in a URI.
 * This method processes a string to make it safe for use in a URI by:
 * - Extracting text before any parentheses (if present)
 * - Converting the string to lowercase
 * - Removing spaces and special characters (except hyphens)
 * * @param string $value The input string to sanitize
 * @return string The sanitized string, suitable for use in a URI
 */
private function sanitizeForUri($value) {
    // Extract text before parentheses if present
    if (preg_match('/^([^(]+)/', $value, $matches)) {
        $value = trim($matches[1]);
    }
     
    // Convert to lowercase and remove spaces and special characters
    $value = preg_replace('/[\s()]+/', '', $value);
    
    return $value;
}

    


/**
 * This method checks the user's permissions against the ACL service to determine if they can create the specfied resource type.
 *
 * @param string $resourceType The type of resource to check (e.g., 'Item', 'ItemSet')
 * @return bool True if the user can create the resource, false otherwise
 */
private function canUserCreateResource($resourceType) 
{
    // user must be logged in to check permissions
    $user = $this->identity();
    if (!$user) {
        return false;
    }
    
    // Get the ACL service
    $acl = $this->getServiceLocator()->get('Omeka\Acl');
    
    // Check if the user has permission to create this resource type
    $canCreate = $acl->userIsAllowed("Omeka\Entity\\$resourceType", 'create');
    

    return $canCreate;
}


/**
 * Processes archaeologist data from the form submission.
 *
 * This method handles both existing and new archaeologists:
 * - For existing archaeologists, it retrieves their data from Omeka.
 * - For new archaeologists, it collects the provided name, ORCID, and email.
 *
 * @param array $formData The form data submitted by the user
 * @return array Processed archaeologist data .
 */
private function processArchaeologistDataFromForm($formData)
{
    $archaeologistData = [
        'existing' => false,
        'name' => null,
        'orcid' => null,
        'email' => null
    ];
    
    // Check if existing archaeologist was selected
    if (isset($formData['existing_archaeologist']) && !empty($formData['existing_archaeologist'])) {
        $archaeologistData['existing'] = true;
        $archaeologistData['item_id'] = $formData['existing_archaeologist'];
        
        // Get the archaeologist data from Omeka
        try {
            $archaeologist = $this->api()->read('items', $formData['existing_archaeologist'])->getContent();
            $values = $archaeologist->values();
            
            // Extract name, ORCID, and email from the archaeologist item
            foreach ($values as $term => $propertyValues) {
                if (!empty($propertyValues) && isset($propertyValues[0])) {
                    $property = $propertyValues[0]->property();
                    if ($property) {
                        $label = $property->label();
                        $value = $propertyValues[0]->value();
                        
                        if (stripos($label, 'name') !== false) {
                            $archaeologistData['name'] = $value;
                        } elseif (stripos($label, 'orcid') !== false || stripos($label, 'account') !== false) {
                            $archaeologistData['orcid'] = str_replace('https://orcid.org/', '', $value);
                        } elseif (stripos($label, 'email') !== false || stripos($label, 'mbox') !== false) {
                            $archaeologistData['email'] = str_replace('mailto:', '', $value);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
   
        }
    } else {
        $archaeologistData['existing'] = false;
        $archaeologistData['name'] = $formData['new_archaeologist_name'] ?? null;
        $archaeologistData['orcid'] = $formData['new_archaeologist_orcid'] ?? null;
        $archaeologistData['email'] = $formData['new_archaeologist_email'] ?? null;
    }
    
    return $archaeologistData;
}






/**
 * Creates a URL-friendly slug from a given string.
 * This method converts the string to lowercase, replaces spaces and special characters with hyphens,
 * and trims leading/trailing hyphens.
 * If the resulting slug is empty, it defaults to 'unknown'.
 * @param string $string The input string to convert into a slug
 * @return string The generated URL slug
 */
private function createUrlSlug($string) {
    // Convert to lowercase
    $slug = strtolower($string);
    
    // Replace spaces and special characters with hyphens
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    
    // Remove leading/trailing hyphens
    $slug = trim($slug, '-');
    
    // Handle empty slugs
    if (empty($slug)) {
        $slug = 'unknown';
    }
    
    return $slug;
}



/**
 * Processes archaeologist data for Turtle (TTL) generation.
 * This method checks if the archaeologist is existing or new and generates a URI accordingly.
 * @param array $archaeologistData The processed archaeologist data containing 'existing', 'item_id', and 'name'
 * @param string $baseUri The base URI used to construct the full archaeollogist URI
 * @return string|null The generated archaeologist URI or null if no valid data
 * 
 */
private function processArchaeologistForTtl($archaeologistData, $baseUri)
{
    if ($archaeologistData['existing'] && !empty($archaeologistData['item_id'])) {
        return "$baseUri/archaeologist/item-" . $archaeologistData['item_id'];
    } elseif (!empty($archaeologistData['name'])) {
        // Create new archaeologist with new URI
        $nameSlug = $this->createUrlSlug($archaeologistData['name']);
        return "$baseUri/archaeologist/$nameSlug";
    }
    
    return null;
}



/**
 * Generate Turtle for a context entity and its SVU relationships.
 *
 * @param string $contextUri URI for the context
 * @param array $context Context data (context_id, context_description)
 * @param array $allEntities Entities with contexts, svus and relationships
 * @param string $baseUri Base URI for related entities
 * @return string TTL string for the context
 */
private function generateContextTtl($contextUri, $context, $allEntities, $baseUri)
{
    $ttl = "<$contextUri> a excav:Context ;\n";
    $ttl .= "    dct:identifier \"" . $context['context_id'] . "\"^^xsd:literal ;\n";
    
    if (!empty($context['context_description'])) {
        $ttl .= "    dct:description \"" . $context['context_description'] . "\"^^xsd:literal ;\n";
    }
    
    if (!empty($allEntities['relationships'])) {
        foreach ($allEntities['relationships'] as $relationship) {
            $contextFound = false;
            foreach ($allEntities['contexts'] as $ctxIndex => $ctx) {
                if ($ctx['context_id'] === $context['context_id'] && $relationship['context'] == $ctxIndex) {
                    $contextFound = true;
                    break;
                }
            }
            
            if ($contextFound && isset($allEntities['svus'][$relationship['svu']])) {
                $svu = $allEntities['svus'][$relationship['svu']];
                $svuSlug = $this->createUrlSlug($svu['svu_id']);
                $svuUri = "$baseUri/svu/$svuSlug";
                $ttl .= "    excav:hasSVU <$svuUri> ;\n";
            }
        }
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}


/**
 * Generate enhanced location Turtle (TTL) data from excavation information.
 *
 * This method cretes Turtle format RDF data that enhances a location with
 * additional geographic and excavation information.
 *
 * @param string $locationUri The URI identifier for the location
 * @param string $gpsUri The URI for the GPS coordinate reference
 * @param array $excavationData Data about the excavation associated with the location
 * @return string The generated Turtle format data
 */
private function generateEnhancedLocationTtl($locationUri, $gpsUri, $excavationData)
{
    $ttl = "";

    // Check if any location data is provided
    $hasLocationData = !empty($excavationData['site_name']) ||
                      !empty($excavationData['district']) ||
                      !empty($excavationData['parish']) ||
                      !empty($excavationData['country']) ||
                      (!empty($excavationData['latitude']) && !empty($excavationData['longitude']));
    
    if (!$hasLocationData) {
   
        return null;
    }       
    
    $ttl .= "<$locationUri> a excav:Location ;\n";
    
    if (!empty($excavationData['site_name'])) {
        $ttl .= "    dbo:informationName \"" . $excavationData['site_name'] . "\"^^xsd:literal ;\n";
    }
    
    $baseUri = dirname(dirname($locationUri)); 
    $entitiesToDeclare = [];
    
    // Add district, parish, and country
    if (!empty($excavationData['district'])) {
        $districtSlug = $this->createUrlSlug($excavationData['district']);
        $districtUri = "http://dbpedia.org/resource/$districtSlug";
        $ttl .= "    dbo:district <$districtUri> ;\n";
        $entitiesToDeclare['district'] = [
            'uri' => $districtUri,
            'label' => $excavationData['district']
        ];
    }
    
    if (!empty($excavationData['parish'])) {
        $parishSlug = $this->createUrlSlug($excavationData['parish']);
        $parishUri = "http://dbpedia.org/resource/$parishSlug";
        $ttl .= "    dbo:parish <$parishUri> ;\n";
        $entitiesToDeclare['parish'] = [
            'uri' => $parishUri,
            'label' => $excavationData['parish']
        ];
    }
    
    if (!empty($excavationData['country'])) {
        $countrySlug = str_replace(' ', '_', $excavationData['country']);
        $countryUri = "http://dbpedia.org/resource/" . $countrySlug;
        $ttl .= "    dbo:Country <$countryUri> ;\n";
        $entitiesToDeclare['country'] = [
            'uri' => $countryUri,
            'label' => $excavationData['country']
        ];
    }
    
    if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
        $ttl .= "    excav:hasGPSCoordinates <$gpsUri> ;\n";
    }

    $ttl .= ".\n";
    if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
        $ttl .= "<$gpsUri> a excav:GPSCoordinates ;\n";
        $ttl .= "    geo:lat \"" . $excavationData['latitude'] . "\"^^xsd:decimal ;\n";
        $ttl .= "    geo:long \"" . $excavationData['longitude'] . "\"^^xsd:decimal .\n\n";
    }

    
    if (!empty($entitiesToDeclare)) {
        $ttl .= "# Type declarations for referenced resources\n";
        
        if (isset($entitiesToDeclare['district'])) {
            $ttl .= "<{$entitiesToDeclare['district']['uri']}> a dbo:District .\n";
        }
        
        if (isset($entitiesToDeclare['parish'])) {
            $ttl .= "<{$entitiesToDeclare['parish']['uri']}> a dbo:Parish .\n";
        }
        if (isset($entitiesToDeclare['country'])) {
            $ttl .= "<{$entitiesToDeclare['country']['uri']}> a dbo:Country .\n";
        }
        
        $ttl .= "\n";
    }
    
    return $ttl;
}


/**
 * Normalize URIs in TTL data for local use.
 * 
 * Converts https://purl.org/megalod/ URIs to http://localhost/megalod/ URIs using item set ID and excavation identifier.
 * Preserves KOS URIs.
 * @param string $ttlData The Turtle data containing URIs to normalize
 * @param string $itemSetId The ID of the item set to use for normalization
 * @return string The modified Turtle data with normalized URIs
 */
private function normalizeUris($ttlData, $itemSetId) {
   

    if (!$itemSetId) {
   
        return $ttlData;
    }
    
    // extract the main excavation identifier
    $excavationIdentifier = null;
   
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
    if ($excavationIdentifier == null){
        if (preg_match('/dct:identifier\s+"([^"]+)"/i', $ttlData, $matches)) {
            $excavationIdentifier = $matches[1]; 
   
        } else {
            $excavationIdentifier = "excavation-$itemSetId";
   
        }
    }
   
    
    $modifiedTtl = $ttlData;
    $replacements = 0;
    
    // Main excavation URI pattern
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/excavation\/([^>]+)>/',
        function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
            $replacements++;
   
            return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier>";
        },
        $modifiedTtl
    );
    
    // Location URI pattern
    $modifiedTtl = preg_replace_callback(
    '/<https:\/\/purl\.org\/megalod\/([^\/]+\/)?location\/([^>]+)>/',
    function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
        if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
        $locationId = $matches[2]; 
        $replacements++;
        $newUri = "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/location/$locationId>";
   
        return $newUri;
    },
    $modifiedTtl
    );
        
        // GPS URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/gps\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
                $gpsId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/gps/$gpsId>";
            },
            $modifiedTtl
        );
        
        // 5. Archaeologist URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/archaeologist\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
                $archaeologistId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/archaeologist/$archaeologistId>";
            },
            $modifiedTtl
        );
        
        // 6. Square URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/square\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
                $squareId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/$squareId>";
            },
            $modifiedTtl
        );
        
        // 7. Context URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/context\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
                $contextId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/context/$contextId>";
            },
            $modifiedTtl
        );
        
        // 8. SVU URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/svu\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
                $svuId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/svu/$svuId>";
            },
            $modifiedTtl
        );
        
        // 9. Timeline URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/timeline\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
                $timelineId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/timeline/$timelineId>";
            },
            $modifiedTtl
        );
        
        // 10. Instant URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/instant\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; 
                $instantId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/instant/$instantId>";
            },
            $modifiedTtl
        );
        
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/[^>]*\/kos\/([^>]+)>/',
            function($matches) {
                return "<https://purl.org/megalod/kos/{$matches[1]}>";
            },
            $modifiedTtl
        );


        // get the item identifier
        $itemIdentifier = null;
        if (preg_match('/dct:identifier\s+"([^"]+)"/i', $modifiedTtl, $matches)) {
            $itemIdentifier = $matches[1]; 
   
        } else {
            $itemIdentifier = "item-$itemSetId";
   
        }
        
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/item\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier>";
            },
            $modifiedTtl
        );

        // Normalize typometry URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/typometry\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $typometryId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/typometry/$typometryId>";
            },
            $modifiedTtl
        );
        
        // Normalize coordinates URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/coordinates\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $coordinatesId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/coordinates/$coordinatesId>";
            },
            $modifiedTtl
        );

        // Normalize weight URIs  
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/weight\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $weightId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/weight/$weightId>";
            },
            $modifiedTtl
        );
        
        // Normalize morphology URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/Morphology\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $morphologyId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/morphology/$morphologyId>";
            },
            $modifiedTtl
        );
        
        // Normalize body length URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/BodyLength\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $bodyLengthId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/bodylength/$bodyLengthId>";
            },
            $modifiedTtl
        );
        
        // Normalize base length URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/BaseLength\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $baseLengthId = $matches[1];
                $replacements++; 
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/baselength/$baseLengthId>";
            },
            $modifiedTtl
        );
        
        // Normalize chipping URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/Chipping\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $chippingId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/chipping/$chippingId>";
            },
            $modifiedTtl
        );

        // normalize gps coordinates URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/gps\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $gpsId = $matches[1];
                $replacements++;
   
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/gps/$gpsId>";
            },
            $modifiedTtl
        );
        
        // Normalize encounter URIs
        $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/encounter\/([^>]+)>/',
        function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
            $encounterId = $matches[1];
            $replacements++;
            
            $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId) ?: "excavation";
            
            $newUri = "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/encounter/$encounterId>";
            
   
            return $newUri;
        },
        $modifiedTtl
    );

    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/([^\/]+)\/item\/([^\/]+)\/encounter\/([^>]+)>/',
        function($matches) use ($itemSetId, &$replacements) {
            $setId = $matches[1];
            $itemId = $matches[2];
            $encounterId = $matches[3];
            
            $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($setId) ?: "excavation";
            
            $newUri = "<http://localhost/megalod/$setId/excavation/$excavationIdentifier/encounter/$encounterId>";
            
            $replacements++;
   
            return $newUri;
        },
        $modifiedTtl
    );
    
    $modifiedTtl = str_replace('<<', '<', $modifiedTtl);
    $modifiedTtl = str_replace('>>', '>', $modifiedTtl);
       
    error_log('normalized: ' . $modifiedTtl, 3, OMEKA_PATH . '/logs/normalizeeeee_uris.log');
    return $modifiedTtl;
}

/**
 * Generates Turtle format RDF
 * 
 * This method converts an SVU entity to TTL
 * 
 * @param string $svuUri The URI identifier for the SVU
 * @param mixed $svu The SVU entity/data to be converted to TTL
 * @return string The generated TTL formatted data
 */
private function generateSvuTtl($svuUri, $svu)
{
    $ttl = "<$svuUri> a excav:StratigraphicVolumeUnit ;\n";
    $ttl .= "    dct:identifier \"" . $svu['svu_id'] . "\"^^xsd:literal ;\n";
    
    if (!empty($svu['svu_description'])) {
        $ttl .= "    dct:description \"" . $svu['svu_description'] . "\"^^xsd:literal ;\n";
    }
    
    if (!empty($svu['svu_lower_year']) || !empty($svu['svu_upper_year'])) {
        $baseUri = dirname(dirname($svuUri)); 
        $svuSlug = basename($svuUri); 
        $timelineUri = "$baseUri/timeline/$svuSlug";
        
        $ttl .= "    excav:hasTimeline <$timelineUri> ;\n";
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}

/**
 * Convert excavation form data to TTL (Turtle) RDF.
 * Generates URIs and TTL sections for excavation, location, archaeologist, squares, contexts, SVUs, and timelines.
 * @param array $excavationData Form data
 * @param string $excavationIdentifier Unique excavation ID
 * @return string TTL RDF
 */
private function processExcavationFormData($excavationData, $excavationIdentifier)
{
   
    
    $baseUri = "https://purl.org/megalod";
    $excavationUri = "$baseUri/excavation/$excavationIdentifier";

    $hasLocationData = !empty($excavationData['site_name']) ||
                      !empty($excavationData['district']) ||
                      !empty($excavationData['parish']) ||
                      !empty($excavationData['country']) ||
                      (!empty($excavationData['latitude']) && !empty($excavationData['longitude']));
    $locationUri = null;
    $gpsUri = null;

    if ($hasLocationData) {
        $siteName = $excavationData['site_name'] ?? 'unknown';
        $siteSlug = $this->createUrlSlug($siteName);
        $locationUri = "$baseUri/location/$siteSlug";
        $gpsUri = "$baseUri/gps/$siteSlug";
    }
    
    // Build TTL data
    $ttl = $this->getTtlPrefixes();
    
    // MAIN EXCAVATION SECTION
    $ttl .= "# ========================================================================================\n";
    $ttl .= "# EXCAVATION DATA - " . strtoupper($excavationData['site_name'] ?? 'ARCHAEOLOGICAL SITE') . "\n";
    $ttl .= "# ========================================================================================\n\n";
    
    $ttl .= "# =========== MAIN EXCAVATION ===========\n\n";
    
    $ttl .= "<$excavationUri> a excav:Excavation ;\n";

    if($excavationIdentifier != null){
            $ttl .= "    dct:identifier \"$excavationIdentifier\"^^xsd:literal ;\n";
    }
    if ($locationUri) {
        $ttl .= "    dul:hasLocation <$locationUri> ;\n";
    }
    
    // Add archaeologist reference
    if (!empty($excavationData['archaeologist']['name'])) {
        $archaeologistUri = $this->processArchaeologistForTtl($excavationData['archaeologist'], $baseUri);
        if ($archaeologistUri) {
            $ttl .= "    excav:hasPersonInCharge <$archaeologistUri> ;\n";
        }
    }
    
    // Add squares
    if (!empty($excavationData['entities']['squares'])) {
        $squareUris = [];
        foreach ($excavationData['entities']['squares'] as $square) {
            $squareSlug = $this->createUrlSlug($square['square_id']);
            $squareUri = "$baseUri/square/$squareSlug";
            $squareUris[] = "<$squareUri>";
        }
        $ttl .= "    excav:hasSquare " . implode(",\n                    ", $squareUris) . " ;\n";
    }
    
    // Add contexts
    if (!empty($excavationData['entities']['contexts'])) {
        $contextUris = [];
        foreach ($excavationData['entities']['contexts'] as $context) {
            $contextSlug = $this->createUrlSlug($context['context_id']);
            $contextUri = "$baseUri/context/$contextSlug";
            $contextUris[] = "<$contextUri>";
        }
        $ttl .= "    excav:hasContext " . implode(",\n                     ", $contextUris) . " .\n\n";
    } else {
        $ttl .= "    .\n\n";
    }
    
    // LOCATION SECTION
    if ($locationUri) {
        $ttl .= "# =========== LOCATION ===========\n\n";
        $locationTtl = $this->generateEnhancedLocationTtl($locationUri, $gpsUri, $excavationData);
        if ($locationTtl) {
            $ttl .= $locationTtl;
        }
    }
    
    // ARCHAEOLOGIST SECTION
    if (!empty($excavationData['archaeologist']['name']) && !$excavationData['archaeologist']['existing']) {
        $ttl .= "# =========== ARCHAEOLOGIST ===========\n\n";
        $archaeologistUri = $this->processArchaeologistForTtl($excavationData['archaeologist'], $baseUri);
        $ttl .= $this->generateArchaeologistTtl($archaeologistUri, $excavationData['archaeologist']);
    }
    
    // SQUARES SECTION
    if (!empty($excavationData['entities']['squares'])) {
        $ttl .= "# =========== EXCAVATION SQUARES ===========\n\n";
        foreach ($excavationData['entities']['squares'] as $square) {
            $squareSlug = $this->createUrlSlug($square['square_id']);
            $squareUri = "$baseUri/square/$squareSlug";
            $ttl .= $this->generateSquareTtl($squareUri, $square);
        }
    }
    
    // CONTEXTS SECTION
    if (!empty($excavationData['entities']['contexts'])) {
        $ttl .= "# =========== CONTEXTS ===========\n\n";
        foreach ($excavationData['entities']['contexts'] as $context) {
            $contextSlug = $this->createUrlSlug($context['context_id']);
            $contextUri = "$baseUri/context/$contextSlug";
            $ttl .= $this->generateContextTtl($contextUri, $context, $excavationData['entities'], $baseUri);
        }
    }
    
    // SVUS SECTION
    if (!empty($excavationData['entities']['svus'])) {
        $ttl .= "# =========== STRATIGRAPHIC VOLUME UNITS ===========\n\n";
        foreach ($excavationData['entities']['svus'] as $svu) {
   
            $svuSlug = $this->createUrlSlug($svu['svu_id']);
            $svuUri = "$baseUri/svu/$svuSlug";
            $ttl .= $this->generateSvuTtl($svuUri, $svu);
        }
    }
    
    // Generate timeline and instant sections if we have SVUs with dates
    $this->generateTimelineAndInstantSections($ttl, $excavationData, $baseUri);
    
   
    
    return $ttl;
}




/**
 * Appends timeline and time instant TTL sections for SVUs with dating info.
 *
 * @param string &$ttl TTL string to append to
 * @param array $excavationData Excavation data with 'entities' and 'svus'
 * @param string $baseUri Base URI for timeline/instant URIs
 */
private function generateTimelineAndInstantSections(&$ttl, $excavationData, $baseUri) {
    if (empty($excavationData['entities']['svus'])) {
        return;
    }
    
    $timelineUris = [];
    $instantUris = [];
    
    foreach ($excavationData['entities']['svus'] as $svu) {
        if (!empty($svu['svu_lower_year']) || !empty($svu['svu_upper_year'])) {
            $svuSlug = $this->createUrlSlug($svu['svu_id']);
            $timelineUri = "$baseUri/timeline/$svuSlug";
            $timelineUris[] = [
                'uri' => $timelineUri,
                'svu' => $svu
            ];
        }
    }
    
    if (!empty($timelineUris)) {
        $ttl .= "# =========== TIMELINES ===========\n\n";
        
        foreach ($timelineUris as $timelineData) {
            $timeline = $timelineData['uri'];
            $svu = $timelineData['svu'];
            
            $ttl .= "<$timeline> a excav:TimeLine ;\n";
            
            if (!empty($svu['svu_lower_year'])) {
                $beginInstantUri = "$timeline/beginning";
                $ttl .= "    time:hasBeginning <$beginInstantUri> ;\n";
                $instantUris[] = [
                    'uri' => $beginInstantUri,
                    'year' => $svu['svu_lower_year'],
                    'bc' => !empty($svu['svu_lower_bc'])
                ];
            }
            
            if (!empty($svu['svu_upper_year'])) {
                $endInstantUri = "$timeline/end";
                $ttl .= "    time:hasEnd <$endInstantUri> .\n\n";
                $instantUris[] = [
                    'uri' => $endInstantUri,
                    'year' => $svu['svu_upper_year'],
                    'bc' => !empty($svu['svu_upper_bc'])
                ];
            } else {
                $ttl .= "    .\n\n";
            }
        }
        
        if (!empty($instantUris)) {
            $ttl .= "# =========== TIME INSTANTS ===========\n\n";
            
            foreach ($instantUris as $instantData) {
                $instantUri = $instantData['uri'];
                $year = $instantData['year'];
                $isBC = $instantData['bc'];
                
                $ttl .= "<$instantUri> a excav:Instant ;\n";
                $ttl .= "    excav:bcad <https://purl.org/megalod/kos/MegaLOD-BCAD/" . ($isBC ? 'BC' : 'AD') . "> ;\n";
                
                $yearValue = abs((int)$year);
                $yearFormatted = str_pad($yearValue, 4, '0', STR_PAD_LEFT);
                
      
                
                $ttl .= "    time:inXSDgYear \"$yearFormatted\"^^xsd:gYear .\n\n";
            }
        }
    }
}


/**
 * This method generates Turtle format RDF for an archaeologist.
 * It creates a TTL string with the archaeologist's URI, name, ORCID, and email.
 * @param mixed $archaeologistUri
 * @param mixed $archaeologistData
 * @return string
 */
private function generateArchaeologistTtl($archaeologistUri, $archaeologistData)
{
    $ttl = "<$archaeologistUri> a excav:Archaeologist ;\n";
    
    if (!empty($archaeologistData['name'])) {
        $ttl .= "    foaf:name \"" . $archaeologistData['name'] . "\"^^xsd:literal ;\n";
    }
    
    if (!empty($archaeologistData['orcid'])) {
        $orcidUrl = "https://orcid.org/" . str_replace('https://orcid.org/', '', $archaeologistData['orcid']);
        $ttl .= "    foaf:account <$orcidUrl> ;\n";
    }
    
    if (!empty($archaeologistData['email'])) {
        // Handle multiple emails if provided
        $emails = is_array($archaeologistData['email']) ? $archaeologistData['email'] : [$archaeologistData['email']];
        foreach ($emails as $email) {
            $emailUrl = "mailto:" . str_replace('mailto:', '', $email);
            $ttl .= "    foaf:mbox <$emailUrl> ;\n";
        }
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}
/**
 * Generates Turtle format RDF for a square.
 * This method creates a TTL string with the square's URI, identifier, and coordinates.
 * @param string $squareUri The URI for the square
 * @param array $square The square data containing identifier and coordinates
 * @return string The generated TTL formatted data for the square
 */
private function generateSquareTtl($squareUri, $square)
{
    $ttl = "<$squareUri> a excav:Square ;\n";
    $ttl .= "    dct:identifier \"" . $square['square_id'] . "\"^^xsd:literal ;\n";
    
    if (!empty($square['square_east_west'])) {
        $ttl .= "    geo:lat \"" . $square['square_east_west'] . "\"^^xsd:decimal ;\n";
    }
    
    if (!empty($square['square_north_south'])) {
        $ttl .= "    geo:long \"" . $square['square_north_south'] . "\"^^xsd:decimal ;\n";
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}



/**
 * Creates an item set data array for an excavation.
 * @param string $excavationIdentifier The identifier for the excavation
 * @param array $excavationData The excavation data containing relevant information
 * @return array The created item set data array
 */
private function createExcavationItemSetData($excavationIdentifier, $excavationData)
{
    $title = "Excavation $excavationIdentifier";
    $description = "Archaeological excavation";
    
    if (!empty($excavationData['site_name'])) {
        $description .= " at " . $excavationData['site_name'];
    }
    
    if (!empty($excavationData['location'])) {
        $description .= " - " . $excavationData['location'];
    }
    
    $itemSetData = [
        'dcterms:title' => [
            [
                'type' => 'literal',
                'property_id' => 1,
                '@value' => $title
            ]
        ],
        'dcterms:description' => [
            [
                'type' => 'literal',
                'property_id' => 4,
                '@value' => $description
            ]
        ],
        'dcterms:identifier' => [
            [
                'type' => 'literal',
                'property_id' => 10,
                '@value' => $excavationIdentifier
            ]
        ],
        'o:is_public' => true
    ];
    
    // Add creator if archaeologist is available
    if (!empty($excavationData['archaeologist']['name'])) {
        $itemSetData['dcterms:creator'] = [
            [
                'type' => 'literal',
                'property_id' => 7665,
                '@value' => $excavationData['archaeologist']['name']
            ]
        ];
    }
    
    return $itemSetData;
}


/** Retrieves the SVU identifier from an Omeka item.
 * This method attempts to extract the identifier using various strategies.
 * @param int $itemId The ID of the Omeka item
 * @return string The extracted or generated SVU identifier
 */
private function getSvuIdentifierFromOmekaItem($itemId) {
    try {
   
        
        $item = $this->api()->read('items', $itemId)->getContent();
        
        // get the dcterms value 
        $values = $item->values();
        
        if (isset($values['dcterms:identifier'])) {
            foreach ($values['dcterms:identifier'] as $value) {
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $identifier = $value->value();
   
                    return $identifier;
                }
            }
        }
        
        // Extract from title 
        $title = $item->displayTitle();
   
        
        // SVU-specific patterns
        $svuPatterns = [
            '/\b(Layer-\d+)\b/',          
            '/\b(CV-\d+-\d+)\b/',           
            '/\b(SVU-\d+)\b/',            
            '/\b(svu-\d+)\b/',            
            '/\b(SU-\d+)\b/',             
        ];
        
        foreach ($svuPatterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
   
                return $matches[1];
            }
        }
        
        // Check resource class to confirm this is an SVU
        $resourceClass = $item->resourceClass();
        if ($resourceClass) {
            $className = strtolower($resourceClass->label());
   
            
            if (strpos($className, 'stratigraphic') !== false || 
                strpos($className, 'svu') !== false || 
                strpos($className, 'unit') !== false) {
                
                // Generate SVU  identifier
                $identifier = "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT);
   
                return $identifier;
            }
        }
        
        if (preg_match('/\b(\d+)\b/', $title, $matches)) {
            $identifier = "Layer-" . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
   
            return $identifier;
        }
        
        // fallback for SVU just as precaution
        $fallbackIdentifier = "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT);
   
        return $fallbackIdentifier;
        
    } catch (\Exception $e) {
   
        return "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT);
    }
}

/**
 * Processes archaeological context selections and generates TTL RDF.
 * This method handles the selection of squares, contexts, and locations,
 * generating appropriate URIs and declarations for each.
 * @param array $formData The form data containing selections
 * @param string $itemSetId The ID of the item set
 * @param string $baseUri The base URI for the excavation
 * @return array An array containing linked resources and declarations
 */
private function processArchaeologicalContextSelections($formData, $itemSetId, $baseUri)
{
    $linkedResources = [];
    $declarations = []; 
    $existingDeclarations = []; 
    
   
    
    // Get excavation identifier
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId) ?: "excavation";
    
    $excavationBaseUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier";

    $realLocationUri = $this->getRealLocationUriFromExcavation($itemSetId);
    if ($realLocationUri && empty($existingDeclarations['location'])) {

        $encounterDefinition = "";
        
        $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
        $locationQuery = "
            PREFIX dbo: <http://dbpedia.org/ontology/>
            PREFIX excav: <https://purl.org/megalod/ms/excavation/>
            
            SELECT ?informationName ?district ?parish ?country
            WHERE {
                GRAPH <$graphUri> {
                    <$realLocationUri> a excav:Location .
                    OPTIONAL { <$realLocationUri> dbo:informationName ?informationName }
                    OPTIONAL { <$realLocationUri> dbo:district ?district }
                    OPTIONAL { <$realLocationUri> dbo:parish ?parish }
                    OPTIONAL { <$realLocationUri> dbo:Country ?country }
                }
            }
            LIMIT 1
        ";
        
        $locationResults = $this->querySparql($locationQuery);
        
        if (!empty($locationResults)) {
            $result = $locationResults[0];
            
            $encounterDefinition .= "<$realLocationUri> a excav:Location ;\n";
            
            if (isset($result['informationName'])) {
                $encounterDefinition .= "    dbo:informationName \"" . $result['informationName']['value'] . "\"^^xsd:literal ;\n";
            }
            
            if (isset($result['district'])) {
                $encounterDefinition .= "    dbo:district <" . $result['district']['value'] . "> ;\n";
            }
            
            if (isset($result['parish'])) {
                $encounterDefinition .= "    dbo:parish <" . $result['parish']['value'] . "> ;\n";
            }
            
            if (isset($result['country'])) {
                $encounterDefinition .= "    dbo:Country <" . $result['country']['value'] . "> ;\n";
            }
            
            $encounterDefinition = rtrim($encounterDefinition, " ;\n") . " .\n\n";
            
            if (isset($result['district'])) {
                $encounterDefinition .= "<" . $result['district']['value'] . "> a dbo:District .\n";
            }
            if (isset($result['parish'])) {
                $encounterDefinition .= "<" . $result['parish']['value'] . "> a dbo:Parish .\n";
            }
            if (isset($result['country'])) {
                $encounterDefinition .= "<" . $result['country']['value'] . "> a dbo:Country .\n";
            }
            
            $encounterDefinition .= "\n";
        }
    }
    if ($realLocationUri) {
        $linkedResources['excav:foundInLocation'] = $realLocationUri;
        
        if (preg_match('/\/location\/([^\/]+)$/', $realLocationUri, $matches)) {
            $locationId = $matches[1];
            $declarations['location'] = [
                'uri' => $realLocationUri,
                'id' => $locationId
            ];
        }
    }
   
    
    // Process selected square
    if (!empty($formData['selected_square'])) {
        $squareItemId = $formData['selected_square'];
   
        
        $realSquareId = $this->getRealIdentifierFromOmekaItem($squareItemId);
        if ($realSquareId) {
            $squareUri = "$excavationBaseUri/square/$realSquareId";
            $linkedResources['excav:foundInSquare'] = $squareUri;
            
            $declarations['square'] = [
                'uri' => $squareUri,
                'id' => $realSquareId
            ];
            
   
        }
    }
    
    // Process selected context
    if (!empty($formData['selected_context'])) {
        $contextItemId = $formData['selected_context'];
        $realContextId = $this->getRealIdentifierFromOmekaItem($contextItemId);
        if ($realContextId) {
            $contextUri = "$excavationBaseUri/context/$realContextId";
            $linkedResources['excav:foundInContext'] = $contextUri;
            
            // Add declaration
            $declarations['context'] = [
                'uri' => $contextUri,
                'id' => $realContextId
            ];
            
   
        }
    }
    
    if (!empty($formData['selected_svu'])) {
        $svuItemId = $formData['selected_svu'];
   
        
        $realSvuId = $this->getSvuIdentifierFromOmekaItem($svuItemId);
   
        if ($realSvuId) {
            $svuUri = "$excavationBaseUri/svu/$realSvuId";
            $linkedResources['excav:foundInSVU'] = $svuUri;
            
            $declarations['svu'] = [
                'uri' => $svuUri,
                'id' => $realSvuId
            ];
            
   
        } 
    }
    
    $linkedResources['excav:foundInExcavation'] = $excavationBaseUri;
    $declarations['excavation'] = [
        'uri' => $excavationBaseUri,
        'id' => $excavationIdentifier
    ];
    
    $locationUri = "$excavationBaseUri/location/excavation-location";
    $linkedResources['excav:foundInLocation'] = $locationUri;
    $declarations['location'] = [
        'uri' => $locationUri,
        'id' => 'excavation-location'
    ];
    
   
   
    
    return [
        'references' => $linkedResources, 
        'declarations' => $declarations
    ];
}

    /**
     * Sanitizes a filename for use in a URI.
     * This method replaces invalid characters with hyphens and ensures the filename is safe for URIs.
     * @param string $filename The original filename
     * @return string The sanitized filename suitable for URIs
     */
    private function sanitizeFilenameForUri($filename) {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        
        // Remove or replace invalid characters 
        $basename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $basename);
        $basename = preg_replace('/-+/', '-', $basename); // Replace multiple hyphens with a single one
        $basename = trim($basename, '-');
        
        if (empty($basename)) {
            $basename = 'file';
        }
        
        if (!empty($extension)) {
            return $basename . '.' . $extension;
        }
        
        return $basename;
    }

    /**
     * Processes the form data for the arrowhead item.
     * This includes extracting relevant information and generating RDF triples.
     * @param array $formData The form data submitted for the arrowhead
     * @param string $itemSetId The ID of the item set
     * @return array An array containing the generated RDF triples
     */
    private function processArrowheadFormData($formData, $itemSetId)
    {
        
        $arrowheadId = !empty($formData['arrowhead_identifier']) 
            ? $formData['arrowhead_identifier'] 
            : 'AH-' . uniqid();
        
        $baseUri = "http://localhost/megalod/$itemSetId/item/$arrowheadId";

        $arrowheadUri = $baseUri;
        $morphologyUri = "$baseUri/morphology/$arrowheadId";
        $chippingUri = "$baseUri/chipping/$arrowheadId";
        $excavationUri = "$baseUri";
        $encounterUri = "$baseUri/encounter/$arrowheadId";
        //gps uri 
        $gpsUri = "$baseUri/gps/$arrowheadId";
        
        // Build TTL data
        $ttl = $this->getTtlPrefixes();
        
        $contextResult = $this->processArchaeologicalContextSelections($formData, $itemSetId, $baseUri);
        $linkedResources = $contextResult['references'];
        $resourceDeclarations = $contextResult['declarations'];
        
        $ttl .= "<$arrowheadUri> a ah:Arrowhead, excav:Item;\n";
        $ttl .= "    dct:identifier \"$arrowheadId\"^^xsd:literal;\n";

        if (!empty($formData['selected_square'])) {
            $squareItemId = $formData['selected_square'];
            $realSquareId = $this->getRealIdentifierFromOmekaItem($squareItemId);
            $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId) ?: "excavation";
            if ($realSquareId) {
                $squareUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/$realSquareId";
                $ttl .= "    excav:foundInSquare <$squareUri>;\n";
        
            }
        }

        $locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
        if ($locationUri) {
            $ttl .= "    excav:foundInLocation <$locationUri>;\n";
        
        } 

        $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
        if ($excavationIdentifier) {
            $excavationUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier";
            $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
        
        }

        $locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
        if ($locationUri && empty($formData['selected_square']) && empty($formData['selected_context']) && empty($formData['selected_svu'])) {
            $ttl .= "    excav:foundInLocation <$locationUri>;\n";
        
        }

        
        if (!empty($formData['arrowhead_annotation'])) {
            $ttl .= "    dbo:Annotation \"" . $formData['arrowhead_annotation'] . "\"^^xsd:literal;\n";
        }
        
        if (!empty($formData['condition_state'])) {
            $value = (stripos($formData['condition_state'], 'true') !== false) ? "true" : "false";
            $ttl .= "    crm:E3_Condition_State \"$value\"^^xsd:boolean;\n";
        }
        
        if (!empty($formData['arrowhead_type'])) {
            $value = (stripos($formData['arrowhead_type'], 'true') !== false) ? "true" : "false";
            $ttl .= "    crm:E55_Type \"$value\"^^xsd:boolean;\n";
        }
        
        if (!empty($formData['elongation_index'])) {
            $ttl .= "    excav:elongationIndex <https://purl.org/megalod/kos/MegaLOD-IndexElongation/" . strtolower($formData['elongation_index']) . ">;\n";
        }

        // thickness index
        if (!empty($formData['thickness_index'])) {
            $ttl .= "    excav:thicknessIndex <https://purl.org/megalod/kos/MegaLOD-IndexThickness/" . strtolower($formData['thickness_index']) . ">;\n";
        }
        
        // Add material
        if (!empty($formData['arrowhead_material'])) {
            $ttl .= "    crm:E57_Material <" . $formData['arrowhead_material'] . ">;\n";
        }
        
        if (!empty($formData['arrowhead_shape'])) {
            $shapeMapping = [
                'triangle' => 'triangle',
                'lozenge-shaped' => 'losangular',
                'losangular' => 'losangular',
                'stemmed' => 'stemmed'
            ];
            
            $shapeSafe = isset($shapeMapping[$formData['arrowhead_shape']]) 
                ? $shapeMapping[$formData['arrowhead_shape']] 
                : strtolower(str_replace('-', '-', $formData['arrowhead_shape']));
                
            $ttl .= "    ah:shape <https://purl.org/megalod/kos/ah-shape/$shapeSafe>;\n";
        }
        
        if (!empty($formData['arrowhead_variant'])) {
            $variantSafe = strtolower($formData['arrowhead_variant']);
            $ttl .= "    ah:variant <https://purl.org/megalod/kos/ah-variant/$variantSafe>;\n";
        }

        if (!empty($formData['gps_latitude']) && !empty($formData['gps_longitude'])) {
            $gpsUri = "$baseUri/gps/$arrowheadId";
            $ttl .= "    excav:hasGPSCoordinates <$gpsUri>;\n";
        }

        if (!empty($formData['encounter_date'])) {
            $ttl .= "    dct:date \"" . $formData['encounter_date'] . "\"^^xsd:literal;\n";
        }
        

        $measurementBlocks = "";
        $processedMeasurements = [];

        $measurements = [
            'height' => 'height',
            'width' => 'width', 
            'weight' => 'weight', 
        ];

        foreach ($measurements as $measurement => $property) {
            $valueKey = $measurement;
            $unitKey = $measurement . '_unit';
            
            if (!empty($formData[$valueKey])) {
                $measurementUri = "$baseUri/typometry/$arrowheadId-$measurement";
                
                if ($measurement === 'weight') {
                    $ttl .= "    schema:weight <$measurementUri>;\n";
                    $measurementBlocks .= "<$measurementUri> a excav:Weight;\n";
                    $measurementBlocks .= "    schema:value \"" . $formData[$valueKey] . "\"^^xsd:decimal;\n";
                    
                    if (!empty($formData[$unitKey]) && !isset($processedMeasurements[$measurementUri])) {
                        $weightUnit = $formData[$unitKey];
                        $measurementBlocks .= "    schema:UnitCode <$weightUnit>;\n";
                        $processedMeasurements[$measurementUri] = true;
                    }
                    $measurementBlocks .= "    .\n\n";
                } else {
                    $ttl .= "    schema:$property <$measurementUri>;\n";
                    $measurementBlocks .= "<$measurementUri> a excav:TypometryValue;\n";
                    $measurementBlocks .= "    schema:value \"" . $formData[$valueKey] . "\"^^xsd:decimal;\n";
                    
                    if (!empty($formData[$unitKey]) && !isset($processedMeasurements[$measurementUri])) {
                        $measurementBlocks .= "    schema:UnitCode <" . $formData[$unitKey] . ">;\n";
                        $processedMeasurements[$measurementUri] = true;
                    }
                    $measurementBlocks .= "    .\n\n";
                }
            }
        }

        if (!empty($formData['thickness'])) {
            $thicknessUri = "$baseUri/typometry/$arrowheadId-thickness";
            if (!isset($processedMeasurements[$thicknessUri])) {
                $ttl .= "    schema:depth <$thicknessUri>;\n";
                $measurementBlocks .= "<$thicknessUri> a excav:TypometryValue;\n";
                $measurementBlocks .= "    schema:value \"" . $formData['thickness'] . "\"^^xsd:decimal;\n";
                if (!empty($formData['thickness_unit'])) {
                    $measurementBlocks .= "    schema:UnitCode <" . $formData['thickness_unit'] . ">;\n";
                }
                $measurementBlocks .= "    .\n\n";
                $processedMeasurements[$thicknessUri] = true;
            }
        }

        if (!empty($formData['body_length'])) {
            $bodyLengthUri = "$baseUri/typometry/$arrowheadId-hasBodyLength";
            if (!isset($processedMeasurements[$bodyLengthUri])) {
                $ttl .= "    ah:hasBodyLength <$bodyLengthUri>;\n";
                $measurementBlocks .= "<$bodyLengthUri> a excav:TypometryValue;\n";
                $measurementBlocks .= "    schema:value \"" . $formData['body_length'] . "\"^^xsd:decimal;\n";
                if (!empty($formData['body_length_unit'])) {
                    $measurementBlocks .= "    schema:UnitCode <" . $formData['body_length_unit'] . ">;\n";
                }
                $measurementBlocks .= "    .\n\n";
                $processedMeasurements[$bodyLengthUri] = true;
            }
        }

        if (!empty($formData['base_length'])) {
            $baseLengthUri = "$baseUri/typometry/$arrowheadId-hasBaseLength";
            if (!isset($processedMeasurements[$baseLengthUri])) {
                $ttl .= "    ah:hasBaseLength <$baseLengthUri>;\n";
                $measurementBlocks .= "<$baseLengthUri> a excav:TypometryValue;\n";
                $measurementBlocks .= "    schema:value \"" . $formData['base_length'] . "\"^^xsd:decimal;\n";
                if (!empty($formData['base_length_unit'])) {
                    $measurementBlocks .= "    schema:UnitCode <" . $formData['base_length_unit'] . ">;\n";
                }
                $measurementBlocks .= "    .\n\n";
                $processedMeasurements[$baseLengthUri] = true;
            }
        }
        
        $hasChippingData = !empty($formData['chipping_mode']) || 
                        !empty($formData['chipping_amplitude']) || 
                        !empty($formData['chipping_direction']);
        
        if ($hasChippingData) {
            $ttl .= "    ah:hasChipping <$chippingUri>;\n";
        }
        
        $hasMorphologyData = !empty($formData['point_definition']) || 
                            !empty($formData['body_symmetry']) || 
                            !empty($formData['arrowhead_base']);
        
        if ($hasMorphologyData) {
            $ttl .= "    ah:hasMorphology <$morphologyUri>;\n";
        }
        
        $coordinatesData = null;
        if (!empty($formData['x_coordinate']) && !empty($formData['y_coordinate'])) {
            $coordinatesUri = "$baseUri/coordinatesInSquare/" . substr($arrowheadId, 3);
            $ttl .= "    excav:hasCoordinatesInSquare <$coordinatesUri>;\n";
        
            $coordinatesData = [
                'uri' => $coordinatesUri,
                'x' => $formData['x_coordinate'],
                'x_unit' => !empty($formData['x_coordinate_unit']) ? $formData['x_coordinate_unit'] : 'CMT',
                'y' => $formData['y_coordinate'],
                'y_unit' => !empty($formData['y_coordinate_unit']) ? $formData['y_coordinate_unit'] : 'CMT',
                'z' => !empty($formData['z_coordinate']) ? $formData['z_coordinate'] : null,
                'z_unit' => !empty($formData['z_coordinate_unit']) ? $formData['z_coordinate_unit'] : 'CMT'
            ];
        }
        
        if (!empty($formData['images'])) {
        $images = $formData['images'];
        if (is_array($images)) {
            foreach ($images as $image) {
                if (!empty($image)) {
                    $imageParts = parse_url($image);
                    if (isset($imageParts['path'])) {
                        $originalFilename = basename($imageParts['path']);
                        $sanitizedFilename = $this->sanitizeFilenameForUri($originalFilename);
                        
                        $imageUri = str_replace($originalFilename, $sanitizedFilename, $image);
                        $ttl .= "    edm:Webresource <$imageUri>;\n";
                    } else {
                        $ttl .= "    edm:Webresource <$image>;\n";
                    }
                }
            }
        } else if (!empty($images)) {
            $imageParts = parse_url($images);
            if (isset($imageParts['path'])) {
                $originalFilename = basename($imageParts['path']);
                $sanitizedFilename = $this->sanitizeFilenameForUri($originalFilename);
                
                $imageUri = str_replace($originalFilename, $sanitizedFilename, $images);
                $ttl .= "    edm:Webresource <$imageUri>;\n";
            } else {
                $ttl .= "    edm:Webresource <$images>;\n";
            }
        }
    }
        
        $ttl .= "    .\n\n";

        // Add entity declarations for referenced resources
        $ttl .= "\n# =========== RESOURCE DECLARATIONS ===========\n\n";
        
        // Excavation declaration
        $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
        if ($excavationIdentifier) {
            $excavationUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier";
            $ttl .= "<$excavationUri> a excav:Excavation ;\n";
            $ttl .= "    dct:identifier \"$excavationIdentifier\"^^xsd:literal .\n\n";
    
        }
        

        $locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
    
        if ($locationUri) {

            $locationData = $this->getLocationDataFromExcavation($itemSetId);
        
            
            $ttl .= "<$locationUri> a excav:Location ;\n";
            
            if ($locationData && !empty($locationData['name'])) {
                $ttl .= "    dbo:informationName \"" . $locationData['name'] . "\"^^xsd:literal ;\n";
                
                if (!empty($locationData['district'])) {
                    $ttl .= "    dbo:district <" . $locationData['district'] . "> ;\n";
                }
                if (!empty($locationData['parish'])) {
                    $ttl .= "    dbo:parish <" . $locationData['parish'] . "> ;\n";
                }
                if (!empty($locationData['country'])) {
                    $ttl .= "    dbo:Country <" . $locationData['country'] . "> ;\n";
                }
            }
        

            $ttl = rtrim($ttl, " ;\n") . " .\n\n";
        }
        
        if (!empty($formData['selected_square'])) {
            $squareItemId = $formData['selected_square'];
            $realSquareId = $this->getRealIdentifierFromOmekaItem($squareItemId);
            if ($realSquareId) {
                $squareUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/$realSquareId";
                $ttl .= "<$squareUri> a excav:Square ;\n";
                $ttl .= "    dct:identifier \"$realSquareId\"^^xsd:literal .\n\n";
    
            }
        }
        
        if (!empty($formData['selected_context'])) {
            $contextItemId = $formData['selected_context'];
            $realContextId = $this->getRealIdentifierFromOmekaItem($contextItemId);
            if ($realContextId) {
                $contextUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/context/$realContextId";
                $ttl .= "<$contextUri> a excav:Context ;\n";
                $ttl .= "    dct:identifier \"$realContextId\"^^xsd:literal .\n\n";
    
            }
        }
        
        if (!empty($formData['selected_svu'])) {
            $svuItemId = $formData['selected_svu'];
            $realSvuId = $this->getRealIdentifierFromOmekaItem($svuItemId);
    
            if ($realSvuId) {
                $svuUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/svu/$realSvuId";
                $ttl .= "<$svuUri> a excav:StratigraphicVolumeUnit ;\n";
                $ttl .= "    dct:identifier \"$realSvuId\"^^xsd:literal .\n\n";
    
            }
        }

        if ($coordinatesData) {
            $ttl .= "<{$coordinatesData['uri']}> a excav:Coordinates;\n";
            $ttl .= "    schema:value \"{$coordinatesData['x']} {$coordinatesData['x_unit']}\"^^xsd:literal;\n";
            $ttl .= "    schema:value \"{$coordinatesData['y']} {$coordinatesData['y_unit']}\"^^xsd:literal;\n";
            
            if ($coordinatesData['z']) {
                $ttl .= "    schema:value \"{$coordinatesData['z']} {$coordinatesData['z_unit']}\"^^xsd:literal;\n";
            }
            
            $ttl .= "    .\n\n";
        }

        if(!empty($formData['gps_latitude']) && !empty($formData['gps_longitude'])) {
            $ttl .= "<$gpsUri> a excav:GPSCoordinates;\n";
            $ttl .= "    geo:lat \"" . $formData['gps_latitude'] . "\"^^xsd:decimal;\n";
            $ttl .= "    geo:long \"" . $formData['gps_longitude'] . "\"^^xsd:decimal;\n";
            $ttl .= "    .\n\n";
        }

        if ($hasMorphologyData) {
            $ttl .= "<$morphologyUri> a ah:Morphology;\n";
            
            if (!empty($formData['point_definition'])) {
                $value = (stripos($formData['point_definition'], 'true') !== false) ? "true" : "false";
                $ttl .= "    ah:point \"$value\"^^xsd:boolean;\n";
            }
            
            if (!empty($formData['body_symmetry'])) {
                $value = (stripos($formData['body_symmetry'], 'true') !== false) ? "true" : "false";
                $ttl .= "    ah:body \"$value\"^^xsd:boolean;\n";
            }
            
            if (!empty($formData['arrowhead_base'])) {
                $baseSafe = strtolower($formData['arrowhead_base']);
                $ttl .= "    ah:base <https://purl.org/megalod/kos/ah-base/$baseSafe>;\n";
            }
            
            $ttl .= "    .\n\n";
        }
        
        if ($hasChippingData) {
            $ttl .= "<$chippingUri> a ah:Chipping;\n";
            
            if (!empty($formData['chipping_mode'])) {
                $modeSafe = strtolower(str_replace('-', '-', $formData['chipping_mode']));
                $ttl .= "    ah:chippingMode <https://purl.org/megalod/kos/ah-chippingMode/$modeSafe>;\n";
            }
            
            if (!empty($formData['chipping_amplitude'])) {
                $value = (stripos($formData['chipping_amplitude'], 'true') !== false) ? "true" : "false";
                $ttl .= "    ah:chippingAmplitude \"$value\"^^xsd:boolean;\n";
            }
            
            if (!empty($formData['chipping_direction'])) {
                $directionSafe = strtolower($formData['chipping_direction']);
                $ttl .= "    ah:chippingDirection <https://purl.org/megalod/kos/ah-chippingDirection/$directionSafe>;\n";
            }
            
            if (!empty($formData['chipping_orientation'])) {
                $value = (stripos($formData['chipping_orientation'], 'true') !== false) ? "true" : "false";
                $ttl .= "    ah:chippingOrientation \"$value\"^^xsd:boolean;\n";
            }
            
            if (!empty($formData['chipping_delineation'])) {
                $delineationSafe = strtolower($formData['chipping_delineation']);
                $ttl .= "    ah:chippingDelineation <https://purl.org/megalod/kos/ah-chippingDelineation/$delineationSafe>;\n";
            }
            
            for ($i = 1; $i <= 3; $i++) {
                $lateralKey = "chipping_location_lateral_$i";
                if (!empty($formData[$lateralKey])) {
                    $locationSafe = strtolower($formData[$lateralKey]);
                    $ttl .= "    ah:chippingLocationSide <https://purl.org/megalod/kos/ah-chippingLocation/$locationSafe>;\n";
                }
            }
            
            for ($i = 1; $i <= 3; $i++) {
                $transversalKey = "chipping_location_transversal_$i";
                if (!empty($formData[$transversalKey])) {
                    $locationSafe = strtolower($formData[$transversalKey]);
                    $ttl .= "    ah:chippingLocationTransversal <https://purl.org/megalod/kos/ah-chippingLocation/$locationSafe>;\n";
                }
            }
            
            if (!empty($formData['chipping_shape'])) {
                $shapeSafe = strtolower($formData['chipping_shape']);
                $ttl .= "    ah:chippingShape <https://purl.org/megalod/kos/ah-chippingShape/$shapeSafe>;\n";
            }
            
            $ttl .= "    .\n\n";
        }
        
        
        $ttl .= $measurementBlocks;
    

        return $ttl;
    }


/**
 * Updates the item set with excavation information.
 * This method adds a description and creator based on the excavation data.
 * @param string $itemSetId The ID of the item set to update
 * @param array $excavationData The excavation data containing location and archaeologist information
 * @return bool True if the update was successful, false otherwise
 */
private function updateItemSetWithExcavationInfo($itemSetId, $excavationData) {
    if (!$itemSetId || empty($excavationData)) {
        return false;
    }
    
    try {
        $updateData = [];
        
        if (!empty($excavationData['location'])) {
            $updateData['dcterms:description'] = [
                [
                    'type' => 'literal',
                    'property_id' => 4,
                    '@value' => "Archaeological excavation at " . $excavationData['location']
                ]
            ];
        }
        
        if (!empty($excavationData['archaeologist'])) {
            $updateData['dcterms:creator'] = [
                [
                    'type' => 'literal',
                    'property_id' => 7, // Dublin Core Creator
                    '@value' => $excavationData['archaeologist']
                ]
            ];
        }
        
        if (!empty($updateData)) {
            $updateResult = $this->api()->update(
                'item_sets', 
                $itemSetId, 
                $updateData, 
                [], 
                ['isPartial' => true]
            );
            
            return $updateResult ? true : false;
        }
        
    } catch (\Exception $e) {
   
        return false;
    }
    
    return true;
}


/**
 * Uploads the arrowhead data and associated media files.
 * This method processes the form data, generates RDF triples, and uploads the data to the specified item set.
 * @param string $ttlData
 * @param string $itemSetId The ID of the item set to upload to
 * @param array $uploadedFiles Optional array of uploaded files
 * @return mixed
 */
private function uploadTtlDataWithMedia($ttlData, $itemSetId, $uploadedFiles) {
    $this->uploadedFiles = $uploadedFiles;
    
    return $this->uploadTtlData($ttlData, $itemSetId);
}




/**
 * This function transforms the collecting form data into the arrowhead data format.
 * @param mixed $formData
 * @return array
 */
private function transformCollectingFormToArrowheadData($formData)
{
   

    $arrowheadData = [];
    
    $fieldMappings = [
        'prompt_53' => 'arrowhead_identifier',    // ID field 
        'prompt_54' => 'images',                  // Images (empty in your case)
        'prompt_55' => 'arrowhead_annotation',    // Observations/annotations
        'prompt_56' => 'condition_state',         // Complete/Broken
        'prompt_57' => 'weight',                  // Weight value
        'prompt_58' => 'weight_unit',             // Weight unit
        'prompt_59' => 'height',                  // Height value
        'prompt_60' => 'height_unit',             // Height unit
        'prompt_61' => 'width',                   // Width value
        'prompt_62' => 'width_unit',              // Width unit
        'prompt_63' => 'thickness',               // Thickness value
        'prompt_64' => 'thickness_unit',          // Thickness unit
        'prompt_65' => 'arrowhead_type',          // Elongate/Short
        'prompt_66' => 'elongation_index',        // Medium/Elongated/Short
        'prompt_100' => 'thickness_index',        // Thin/Medium/Thick
        'prompt_67' => 'gps_latitude',            // GPS latitude 
        'prompt_68' => 'gps_longitude',           // GPS longitude 
        'prompt_69' => 'arrowhead_variant',       // Flat/Raised/Thick
        'prompt_70' => 'arrowhead_shape',         // Triangle/Losangular/Stemmed
        'prompt_71' => 'point_definition',        // Sharp/Fractured
        'prompt_72' => 'body_symmetry',           // Symmetrical/Non-symmetrical
        'prompt_73' => 'arrowhead_base',          // Base type
        'prompt_74' => 'body_length',             // Body length value
        'prompt_75' => 'body_length_unit',        // Body length unit
        'prompt_76' => 'base_length',             // Base length value
        'prompt_77' => 'base_length_unit',        // Base length unit
        
        // Chipping properties
        'prompt_78' => 'chipping_mode',           // Plane/Parallel/Sub-parallel
        'prompt_79' => 'chipping_amplitude',      // Marginal/Deep
        'prompt_80' => 'chipping_direction',      // Direct/Reverse/Bifacial
        'prompt_81' => 'chipping_orientation',    // Side/Transverse
        'prompt_82' => 'chipping_delineation',    // Continuous/Composite/Denticulated
        'prompt_83' => 'chipping_location_lateral_1',    // Distal/Median/Proximal
        'prompt_84' => 'chipping_location_lateral_2',
        'prompt_85' => 'chipping_location_lateral_3',
        'prompt_86' => 'chipping_location_transversal_1', // Distal/Median/Proximal
        'prompt_87' => 'chipping_location_transversal_2',
        'prompt_88' => 'chipping_location_transversal_3',
        'prompt_89' => 'chipping_shape',          // Straight/Convex/Concave/Sinuous
        
        // Square coordinates
        'prompt_90' => 'x_coordinate',
        'prompt_91' => 'y_coordinate',
        'prompt_92' => 'z_coordinate',
        'prompt_93' => 'arrowhead_material',      // Material
        'prompt_94' => 'x_coordinate_unit',       // X coordinate unit
        'prompt_95' => 'y_coordinate_unit',       // Y coordinate unit  
        'prompt_96' => 'z_coordinate_unit',       // Z coordinate unit

        // ENCOUNTER DATe
        'prompt_99' => 'encounter_date',          // Encounter date mm-dd-aa
    ];

    foreach ($fieldMappings as $collectingField => $arrowheadField) {
        if (isset($formData[$collectingField]) && !empty($formData[$collectingField])) {
            $value = $formData[$collectingField];
            
            if (strpos($value, 'True') === 0 || strpos($value, 'true') === 0) {
                $arrowheadData[$arrowheadField] = 'true';
            } elseif (strpos($value, 'False') === 0 || strpos($value, 'false') === 0) {
                $arrowheadData[$arrowheadField] = 'false';
            } else {
                $cleanValue = preg_replace('/\s*\([^)]*\)/', '', $value);
                $arrowheadData[$arrowheadField] = trim($cleanValue);
            }
        }
    }
    if (!empty($formData['selected_square'])) {
        $arrowheadData['selected_square'] = $formData['selected_square'];
   
    }
    
    if (!empty($formData['selected_context'])) {
        $arrowheadData['selected_context'] = $formData['selected_context'];
   
    }
    
    if (!empty($formData['selected_svu'])) {
        $arrowheadData['selected_svu'] = $formData['selected_svu'];
   
    }

    if (isset($formData['file']['54']) && is_array($formData['file']['54'])) {
        $imageFiles = $formData['file']['54'];
   
        $imageUrls = [];
        
        foreach ($imageFiles as $imageFile) {
            if (!empty($imageFile)) {
                
                $baseUrl = "http://localhost/megalod/images/";
                $filename = basename($imageFile);
                $imageUrls[] = $baseUrl . $filename;
            }
        }
        
        if (!empty($imageUrls)) {
            $arrowheadData['images'] = $imageUrls;
        }
    }

    $itemSetId = isset($formData['item_set_id']) ? $formData['item_set_id'] : null;
    
    if ($itemSetId) {
        try {
            $locationUri = $this->getExcavationLocationUri(null, $itemSetId);
            
            if ($locationUri) {
   
                $arrowheadData['location'] = $locationUri;
            }
        } catch (\Exception $e) {
   
        }
    }

    $valuesToCheck = [
        'thickness' => 'thickness_unit',
        'body_length' => 'body_length_unit', 
        'base_length' => 'base_length_unit'
    ];
    
    foreach ($valuesToCheck as $valueField => $unitField) {
        if (empty($arrowheadData[$valueField]) && isset($arrowheadData[$unitField])) {
            unset($arrowheadData[$unitField]);
   
        }
    }
   

    return $arrowheadData;
}

/**
 * This function gets the excavation location uri
 * @param mixed $excavationId
 * @param mixed $itemSetId
 * @return string
 */
private function getExcavationLocationUri($excavationId, $itemSetId = null) {
    $baseId = $itemSetId ?: $excavationId;
    return "http://localhost/megalod/$baseId/location/excavation-location";
}


/**
 * This function processes the upload of a file
 * @param mixed $request
 * @param mixed $uploadType
 * @param mixed $itemSetId
 * @throws \Exception
 * @return string
 */
private function processFileUpload($request, ?string $uploadType, ?int $itemSetId): string
{
    $file = $request->getFiles()->file;
    if (empty($file['tmp_name'])) {
        return 'No file uploaded or file upload error.';
    }

    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileType = $file['type'];

    if (strtolower($fileExtension) === 'ttl' && $fileType !== 'application/x-turtle') {
        $fileType = 'application/x-turtle';
    }

    if (!in_array($fileType, ['application/x-turtle', 'application/xml', 'text/xml'])) {
        return 'Invalid file type. Please upload a valid .ttl or .xml file.';
    }

   
    try {
        if (!isset($_FILES['file']) || empty($_FILES['file']['tmp_name'])) {
   
        return 'Error: No file uploaded';
    }
        if ($fileType === 'application/xml' || $fileType === 'text/xml') {
            $rdfXmlData = $this->xmlParser($file);
            if (is_string($rdfXmlData) && strpos($rdfXmlData, 'Failed') === false) {
                $ttlData = $this->xmlTtlConverter($rdfXmlData);
   
            } else {
                throw new \Exception('Failed to process XML file: ' . $rdfXmlData);
            }
        } else {
            $ttlData = file_get_contents($file['tmp_name']);
        }

   
   

        
        if ($uploadType) {
            try {
                $this->validateUploadType($ttlData, $uploadType);
   


            } catch (\Exception $e) {
                
            }
        }
   
   

        $result = $this->uploadTtlData($ttlData, $itemSetId);
        return $result;
    } catch (\Exception $e) {
        return 'Error processing file: ' . $e->getMessage();
    }
}

    

/**
 * Uploads TTL data to the specified item set.
 * This method handles excavation data, normalizes URIs, and validates context relationships.
 * @param string $ttlData The TTL data to upload
 * @param int|null $itemSetId The ID of the item set to upload to
 * @return string Result message indicating success or failure
 */
private function uploadTtlData(string $ttlData, ?int $itemSetId = null): string {
   
    // Set the current processing context
    $this->currentProcessingItemSetId = $itemSetId;
    
    try {
   
        $isExcavation = false;
        $excavationIdentifier = "0"; 

        try {
            $this->validateUploadType($ttlData, 'excavation');
   
            $isExcavation = true;

   

            $extractedId = $this->extractExcavationIdentifier($ttlData);
   
            if ($extractedId) {
                $excavationIdentifier = $extractedId;
   
            } else {
                
            }
        } catch (\Exception $e) {
               if ($itemSetId) {
   
                    $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
                    if ($excavationId) {
                        $excavationIdentifier = $excavationId;
    
                    }
                }
        }


        // Normalize URIs based on context
        if ($itemSetId) {
   
            $ttlData = $this->normalizeUris($ttlData, $itemSetId);
   
        } elseif ($isExcavation && $excavationIdentifier) {
   
            if ($this->excavationIdentifierExists($excavationIdentifier)) {
                
   
            }
            
            $excavationMetadata = $this->extractExcavationMetadataFromTtl($ttlData);
            
            try {
                // create itemset
                $itemSetTitle = "Excavation $excavationIdentifier";
                $itemSetDescription = $excavationMetadata['location'] ? 
                    "Archaeological excavation at " . $excavationMetadata['location'] : 
                    "Archaeological excavation with identifier $excavationIdentifier";
                
                $response = $this->api()->create('item_sets', [
                    'dcterms:title' => [
                        [
                            'type' => 'literal',
                            'property_id' => 1,
                            '@value' => $itemSetTitle
                        ]
                    ],
                    'dcterms:description' => [
                        [
                            'type' => 'literal',
                            'property_id' => 4,
                            '@value' => $itemSetDescription
                        ]
                    ],
                    'dcterms:creator' => $excavationMetadata['archaeologist'] ? [
                        [
                            'type' => 'literal',
                            'property_id' => 7665,
                            '@value' => $excavationMetadata['archaeologist']
                        ]
                    ] : [],
                    'o:is_public' => true
                ]);
                
                if ($response) {
                    $newItemSet = $response->getContent();
                    $itemSetId = $newItemSet->id();
                    
   
                    
                    $this->currentProcessingItemSetId = $itemSetId;
   
                    
                    $ttlData = $this->normalizeUris($ttlData, $itemSetId);
   
                    
                    // Store the mapping between item set and excavation
                    $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
                }
            } catch (\Exception $e) {
   
                return 'Error: Failed to create excavation item set - ' . $e->getMessage();
            }
        }
   

   

        // deal with encounter validation for arrowheads
        $isArrowhead = strpos($ttlData, 'ah:Arrowhead') !== false || strpos($ttlData, 'excav:Item') !== false;
   
   
   
        if ($isArrowhead && $itemSetId) {
   
   
        
   
   
            $arrowheadContext = $this->extractArrowheadContextFromTtl($ttlData);
   
            
            $validationResult = $this->validateContextRelationships($arrowheadContext, $itemSetId);            // log the validation result
   
            
            if (!$validationResult['valid']) {

                $errorDetails = "\n\nValidation Details:\n" . json_encode($validationResult['details'], JSON_PRETTY_PRINT);
                return 'Validation Error: ' . $validationResult['error'] . $errorDetails;
            }
            
   
            
            $encounterEvent = $this->findOrCreateEncounterEvent($arrowheadContext, $itemSetId);
   
            
            $ttlData = $this->addEncounterEventToTtl($ttlData, $encounterEvent, $itemSetId);
   
   
        }

        error_log('ttldata: ' . $ttlData, 3, OMEKA_PATH . '/logs/normalizeeeee_uris.log');

        $graphDbResult = $this->sendToGraphDB($ttlData, $itemSetId);
   
        
        
        if (strpos($graphDbResult, 'successfully') !== false) {
            // If GraphDB upload is successful, then process in Omeka S
            $omekaData = $this->transformTtlToOmekaSData($ttlData, $itemSetId);

            $omekaResponse = $this->sendToOmekaS($omekaData, $itemSetId);
            
            if (empty($omekaResponse['errors'])) {
                $createdItems = $omekaResponse['created_items'];
                $updatedCount = 0;
                
                foreach ($createdItems as $item) {
                    if (is_array($item) && isset($item['o:id'])) {
                        $itemId = $item['o:id']; // Get the Omeka assigned IdD
                    }
                    else {
   
                        $itemId = null;
                    }
                    
                    if ($isExcavation) {
                        $title = "Excavation $excavationIdentifier Item $itemId";
                    } else {
                        $title = "Arrowhead $itemId" . ($excavationIdentifier ? " (Excavation $excavationIdentifier)" : "");
                    }
                    
                    try {
                        $updateResult = $this->api()->update('items', $itemId, [
                            'dcterms:title' => [
                                [
                                    'type' => 'literal',
                                    'property_id' => 1,
                                    '@value' => $title
                                ]
                            ]
                        ], [], ['isPartial' => true]);
                        
                        if ($updateResult) {
                            $updatedCount++;
   
                        }
                    } catch (\Exception $e) {
   
                    }
                }
                
                if ($isExcavation && $itemSetId) {
                    return "Data uploaded successfully to both GraphDB and Omeka S. Created Item Set #{$itemSetId} for excavation '$excavationIdentifier' and " . 
                          count($createdItems) . " items with updated titles.";
                } else {
                    return 'Data uploaded successfully to both GraphDB and Omeka S. Created ' . 
                          count($createdItems) . ' items with updated titles and proper resource links within excavation context.';
                }
            } else {
                return 'Data uploaded to GraphDB, but Omeka S errors: ' . 
                      implode('; ', $omekaResponse['errors']);
            }
        } else {
            return 'Failed to upload data to GraphDB: ' . $graphDbResult;
        }
        
    } finally {
        $this->currentProcessingItemSetId = null;
   
    }
}


/**
 * This method extracts the excavation data from the ttl file
 * @param mixed $ttlData
 * @return array|array{archaeologist: null, location: null}
 */
private function extractExcavationMetadataFromTtl($ttlData) {
    $metadata = [
        'location' => null,
        'archaeologist' => null
    ];
    
    // Extract location name
    if (preg_match('/dbo:informationName\s+"([^"]+)"/i', $ttlData, $matches)) {
        $metadata['location'] = $matches[1];
    }
    
    // Extract archaeologist name  
    if (preg_match('/foaf:name\s+"([^"]+)"/i', $ttlData, $matches)) {
        $metadata['archaeologist'] = $matches[1];
    }
    
    return $metadata;
}


/**
 * Checks if the excavation identifier already exists in the system.
 * @param string $excavationIdentifier The excavation identifier to check
 * @return bool True if the identifier exists, false otherwise
 */
private function excavationIdentifierExists($excavationIdentifier) {
    if (empty($excavationIdentifier)) {
        return false;
    }
    
    try {
        $response = $this->api()->search('items', [
            'property' => [
                [
                    'property' => 10, 
                    'type' => 'eq',
                    'text' => $excavationIdentifier
                ]
            ]
        ]);
        
        return $response->getTotalResults() > 0;
        
    } catch (\Exception $e) {
   
        return false;
    }
}

/**
 * Stores the mapping between an item set and an excavation identifier.
 * This method updates the site settings to maintain the relationship.
 * @param int $itemSetId The ID of the item set
 * @param string $excavationId The excavation identifier
 */
private function storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationId)
{
    $mappings = $this->siteSettings()->get('excavation_itemset_mappings', []);
    
    $mappings[$itemSetId] = $excavationId;
    
    // Save the updated mappings
    $this->siteSettings()->set('excavation_itemset_mappings', $mappings);
    
   
}


/** Extracts the excavation identifier from the TTL data.
 * This method looks for both dct:identifier and dcterms:identifier patterns.
 * @param string $ttlData The TTL data to search
 * @return string|null The extracted excavation identifier or null if not found
 */
private function extractExcavationIdentifier(string $ttlData): ?string {
   
    
    if (preg_match('/dct:identifier\s+"([^"]+)"\^\^xsd:literal/', $ttlData, $matches)) {
   
        return $matches[1];
    }
    
    // Alternative dcterms:identifier
    if (preg_match('/dcterms:identifier\s+"([^"]+)"/', $ttlData, $matches)) {
   
        return $matches[1];
    }
    return null;
}


/**
 * Validates the upload type against the content of the TTL data.
 * This method checks if the TTL data contains patterns specific to excavations or arrowheads.
 * @param string $ttlData The TTL data to validate
 * @param string|null $uploadType The type of upload (excavation or arrowhead)
 * @throws \Exception If the validation fails
 */
private function validateUploadType(string $ttlData, ?string $uploadType): void
{
    if (!$uploadType) {
        return; 
    }

   
   
    
    $excavationPatterns = [
        'a excav:Excavation',
        'excav:Excavation',
        'crmarchaeo:A9_Archaeological_Excavation',
        'a crmarchaeo:A9_Archaeological_Excavation',
        'excav:hasPersonInCharge',
        'excav:hasSquare',
        'excav:hasContext'
    ];
    
    $arrowheadPatterns = [
        'a ah:Arrowhead',
        'ah:Arrowhead',
        'a excav:Item',
        'excav:Item',
        'ah:shape',
        'ah:variant',
        'ah:hasMorphology',
        'ah:hasChipping'
    ];
    
    $isExcavation = false;
    foreach ($excavationPatterns as $pattern) {
        if (strpos($ttlData, $pattern) !== false) {
            $isExcavation = true;
   
            break;
        }
    }
    
    $isArrowhead = false;
    foreach ($arrowheadPatterns as $pattern) {
        if (strpos($ttlData, $pattern) !== false) {
            $isArrowhead = true;
   
            break;
        }
    }
    
    
    if ($uploadType === 'excavation' && !$isExcavation) {
        if ($isArrowhead) {
   
            return; 
        }
        throw new \Exception('Invalid data type for excavation upload.');
    } elseif ($uploadType === 'arrowhead' && !$isArrowhead) {
        throw new \Exception('Invalid data type for Arrowhead upload.');
    }
    
   
}

/**
 * Parses the uploaded XML file and applies the appropriate XSLT transformation.
 * This method detects the type of XML (Arrowhead or Excavation) and applies the corresponding XSLT.
 * @param array $file The uploaded XML file
 * @return string|false The transformed RDF-XML data or an error message
 */
public function xmlParser($file)
{
   
    $xmlContent = file_get_contents($file['tmp_name']);
    
    if (strpos($xmlContent, '<item id="AH') !== false || 
        strpos($xmlContent, 'arrowhead') !== false ||
        strpos($xmlContent, '<ah:') !== false) {
        $xsltPath = OMEKA_PATH . '/modules/AddTriplestore/asset/xlst/arrowXslt.xml'; // Arrowhead XSLT
   
    } elseif (strpos($xmlContent, '<Excavation') !== false || 
              strpos($xmlContent, 'excavation') !== false ||
              strpos($xmlContent, '<excav:') !== false) {
        $xsltPath = OMEKA_PATH . '/modules/AddTriplestore/asset/xlst/excavationXslt.xml'; // Excavation XSLT
   
    } else {
   
        return 'Could not determine XML type';
    }

    if (!file_exists($xsltPath)) {
   
        return 'XSLT file not found';
    }

    $xslt = new \DOMDocument();
    $xslt->load($xsltPath);

    $xmlDoc = new \DOMDocument();
    if (!$xmlDoc->load($file['tmp_name'])) {
   
        return 'Failed to load XML file';
    }

    $processor = new \XSLTProcessor();
    
    $processor->importStylesheet($xslt);
    
    
    $rdfXmlConverted = $processor->transformToXML($xmlDoc);

    if (!$rdfXmlConverted) {
   
        return 'Failed to convert XML to RDF-XML';
    }

   
    return $rdfXmlConverted;
}


/**
 * Converts RDF/XML data to Turtle format with proper namespaces and cleanup.
 * This method registers necessary namespaces, cleans the RDF-XML, and serializes it to Turtle format.
 * @param string $rdfXmlData The RDF-XML data to convert
 * @return string The cleaned Turtle data
 */
public function xmlTtlConverter($rdfXmlData)
{
   
    // Register necessary namespaces
    \EasyRdf\RdfNamespace::set('dct', 'http://purl.org/dc/terms/');
    \EasyRdf\RdfNamespace::set('ah', 'https://purl.org/megalod/ms/ah/');
    \EasyRdf\RdfNamespace::set('excav', 'https://purl.org/megalod/ms/excavation/');
    \EasyRdf\RdfNamespace::set('dct', 'http://purl.org/dc/terms/');
    \EasyRdf\RdfNamespace::set('dul', 'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#');
    // Clean the RDF XML
    $cleanedRdfXml = $this->cleanRdfXmlNamespaces($rdfXmlData);
   
    
    $rdfGraph = new \EasyRdf\Graph();
    $rdfGraph->parse($cleanedRdfXml, 'rdfxml');

   
    $ttlData = $rdfGraph->serialise('turtle');
   
    // Clean up the Turtle output
    $cleanTtl = $this->cleanupTtlOutput($ttlData);
   
    
   
    return $cleanTtl;
}

/**
 * Cleans the RDF/XML data by ensuring proper namespace declarations.
 * This method sets the required namespaces on the root element of the RDF/XML.
 * @param string $rdfXmlData The RDF/XML data to clean
 * @return string The cleaned RDF/XML data with proper namespaces
 */
private function cleanRdfXmlNamespaces($rdfXmlData)
{
    $namespaces = [
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#', 
        'sh' => 'http://www.w3.org/ns/shacl#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'dct' => 'http://purl.org/dc/terms/',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'dbo' => 'http://dbpedia.org/ontology/',
        'crm' => 'http://www.cidoc-crm.org/cidoc-crm/',
        'crmsci' => 'http://cidoc-crm.org/extensions/crmsci/',
        'crmarchaeo' => 'http://www.cidoc-crm.org/extensions/crmarchaeo/',
        'edm' => 'http://www.europeana.eu/schemas/edm/',
        'geo' => 'http://www.w3.org/2003/01/geo/wgs84_pos#',
        'time' => 'http://www.w3.org/2006/time#',
        'schema' => 'http://schema.org/',
        'ah' => 'https://purl.org/megalod/ms/ah/',
        'excav' => 'https://purl.org/megalod/ms/excavation/',
        'dul' => 'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#'
        

    ];
    
    $dom = new \DOMDocument();
    $dom->loadXML($rdfXmlData);
    
    $root = $dom->documentElement;
    if ($root) {
        foreach ($namespaces as $prefix => $namespace) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$prefix", $namespace);
        }
    }
    
    return $dom->saveXML();
}

/**
 * Cleans up the Turtle output by removing unnecessary prefixes and applying formatting.
 * This method removes auto-generated namespace prefixes, applies custom replacements, and formats the Turtle data.
 * @param string $ttlData The Turtle data to clean
 * @return string The cleaned Turtle data with proper formatting
 */
private function cleanupTtlOutput($ttlData)
{
    // Remove auto-generated namespace prefixes and replace with clean ones
    $cleanTtl = $this->getTtlPrefixes();
    
    // Remove existing @prefix lines from the TTL
    $lines = explode("\n", $ttlData);
    $contentLines = [];
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!empty($trimmed) && strpos($trimmed, '@prefix') !== 0) {
            $contentLines[] = $line;
        }
    }
    
    $content = implode("\n", $contentLines);
    
    // Apply namespace replacements
    $content = $this->replaceNamespacePrefixes($content);
    
    // Apply specific TTL formatting improvements
    $content = $this->applyTtlFormatting($content);
    
    return $cleanTtl . "\n" . $content;
}


/**
 * Replaces namespace prefixes in the Turtle content with the desired prefixes.
 * @param string $content The Turtle content to modify
 * @return string The modified Turtle content with replaced namespace prefixes
 */
private function replaceNamespacePrefixes($content)
{

    if (strpos($content, 'ah:Arrowhead') !== false || strpos($content, 'excav:Item') !== false) {
        $replacements = [
            '/ns0:/' => 'edm:',
            '/ns1:/' => 'dbo:',
            '/ns2:/' => 'crm:',
        ];
    } else {
        $replacements = [
            '/ns0:/' => 'dbo:',
        ];
    }
    
    foreach ($replacements as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    // Replace common URI patterns with prefixed versions
    $uriReplacements = [
        '<https://purl.org/megalod/ms/ah/' => '<ah:',
        '<https://purl.org/megalod/ms/excavation/' => '<excav:',
        '<http://purl.org/dc/terms/' => '<dct:',
        '<http://xmlns.com/foaf/0.1/' => '<foaf:',
        '<http://dbpedia.org/ontology/' => '<dbo:',
        '<http://www.cidoc-crm.org/cidoc-crm/' => '<crm:',
        '<http://cidoc-crm.org/extensions/crmsci/' => '<crmsci:',
        '<http://www.europeana.eu/schemas/edm/' => '<edm:',
        '<http://www.w3.org/2003/01/geo/wgs84_pos#' => '<geo:',
        '<http://www.w3.org/2006/time#' => '<time:',
        '<http://schema.org/' => '<schema:',
        '<http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#' => '<dul:',
        '<http://www.w3.org/1999/02/22-rdf-syntax-ns#' => '<rdf:',
        '<http://www.w3.org/2001/XMLSchema#' => '<xsd:'
    ];
    
    foreach ($uriReplacements as $uri => $prefix) {
        $content = str_replace($uri, $prefix, $content);
    }
    
    $content = preg_replace('/dc:identifier/', 'dct:identifier', $content);
    $content = preg_replace('/dc:date/', 'dct:date', $content);
    $content = preg_replace('/dc:description/', 'dct:description', $content);
    
    return $content;
}

/**
 * Applies formatting improvements to the Turtle content.
 * @param string $content The Turtle content to format
 * @return string The formatted Turtle content
 */
private function applyTtlFormatting($content)
{
    // Fix boolean values
    $content = preg_replace('/"true"\^\^xsd:boolean/', 'true', $content);
    $content = preg_replace('/"false"\^\^xsd:boolean/', 'false', $content);
    
    // Clean up datatype declarations that are redundant
    $content = preg_replace('/"\^\^xsd:literal/', '"^^xsd:literal', $content);
    
    // Ensure proper KOS URI formatting
    $content = $this->fixKosUris($content);
    
    // Fix year formatting
    $content = preg_replace_callback(
        '/time:inXSDgYear "(-?\d+)"\^\^xsd:gYear/',
        function($matches) {
            $year = $matches[1];
            // Ensure proper formatting
            if (strpos($year, '-') === 0) {
                $year = str_replace('-', '', $year);
                $year = '-' . str_pad($year, 4, '0', STR_PAD_LEFT);
            } else {
                $year = str_pad($year, 4, '0', STR_PAD_LEFT);
            }
            return 'time:inXSDgYear "' . $year . '"^^xsd:gYear';
        },
        $content
    );
    
    $content = str_replace('>', '>', $content);
    
    return $content;
}

/**
 * Fixes KOS URIs to ensure they follow the correct format.
 * This method replaces specific patterns in the Turtle content with the correct KOS URIs.
 * @param string $content The Turtle content to modify
 * @return string The modified Turtle content with fixed KOS URIs
 */
private function fixKosUris($content)
{
    $kosPatterns = [
        '/ah-shape:(\w+)/' => '<https://purl.org/megalod/kos/ah-shape/$1>',
        '/ah-variant:(\w+)/' => '<https://purl.org/megalod/kos/ah-variant/$1>',
        '/ah-base:(\w+)/' => '<https://purl.org/megalod/kos/ah-base/$1>',
        '/ah-chippingMode:(\w+)/' => '<https://purl.org/megalod/kos/ah-chippingMode/$1>',
        '/ah-chippingDirection:(\w+)/' => '<https://purl.org/megalod/kos/ah-chippingDirection/$1>',
        '/ah-chippingDelineation:(\w+)/' => '<https://purl.org/megalod/kos/ah-chippingDelineation/$1>',
        '/ah-chippingLocation:(\w+)/' => '<https://purl.org/megalod/kos/ah-chippingLocation/$1>',
        '/ah-chippingShape:(\w+)/' => '<https://purl.org/megalod/kos/ah-chippingShape/$1>',
        '/MegaLOD-IndexElongation:(\w+)/' => '<https://purl.org/megalod/kos/MegaLOD-IndexElongation/$1>',
        '/MegaLOD-IndexThickness:(\w+)/' => '<https://purl.org/megalod/kos/MegaLOD-IndexThickness/$1>',
        '/MegaLOD-BCAD:(\w+)/' => '<https://purl.org/megalod/kos/MegaLOD-BCAD/$1>'
    ];
    
    foreach ($kosPatterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    return $content;
}



/**
 * Sends the Turtle data to GraphDB for validation and upload.
 * This method handles SHACL validation, uploads the data, and logs the results.
 * @param string $data The Turtle data to upload
 * @param int|null $excavationId The ID of the excavation or item set
 * @return string Result message indicating success or failure
 */
private function sendToGraphDB($data, $excavationId)
{
    $logger = new Logger();
    $writer = new Stream(OMEKA_PATH . '/logs/graphdb-errors.log');

    $logger->addWriter($writer);

    // Set the graph URI based on excavation ID if provided
    $graphUri = $this->baseDataGraphUri;
    
    // Use the provided excavation ID or default to "0"
    $this->excavationIdentifier = $excavationId ?: "0";
    $graphUri .= $this->excavationIdentifier . "/";
    
   

    try {
        $validationResult = $this->validateData($data, $graphUri);   
        if (!empty($validationResult)) {
            $errorMessage = 'Data upload failed: SHACL validation errors: ' . implode('; ', $validationResult);
   
            $logger->err($errorMessage);
            return $errorMessage;
        }

        $credentials = $this->getGraphDBCredentials();

        $client = new Client();
        $fullUrl = $this->graphdbEndpoint . '?graph=' . urlencode($graphUri);
   
        
        $client->setUri($fullUrl);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'text/turtle',
            'Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['password'])
        ]);
        $client->setRawBody($data);

        $client->setOptions(['timeout' => 60]); 

        $response = $client->send();

        $status = $response->getStatusCode();
        $body = $response->getBody();
        $message = "Response Status: $status | Response Body: $body";
   
        $logger->info($message);


        if ($status == 401) {
            $errorMessage = "Authentication failed with GraphDB. Please check your credentials.";
   
            $logger->err($errorMessage);
            return $errorMessage;
        }


        if ($response->isSuccess()) {
            return 'Data uploaded and validated successfully.';
        } else {
            $errorMessage = 'Failed to upload data: ' . $message;
   
            $logger->err($errorMessage);
            return $errorMessage;
        }
    } catch (\Exception $e) {
        $errorMessage = 'Failed to upload data due to an exception: ' . $e->getMessage();
        $logger->err($errorMessage);
   
        return $errorMessage;
    }
    
}



/**
 * Retrieves GraphDB credentials from the configuration file.
 * This method checks for a custom configuration file and returns the credentials.
 * If not found, it defaults to admin:admin.
 * @return array An associative array with 'username' and 'password'
 */
private function getGraphDBCredentials()
{
    $configFile = OMEKA_PATH . '/modules/AddTriplestore/config/graphdb.config.php';
    if (file_exists($configFile)) {
        $credentials = include $configFile;
        if (isset($credentials['username']) && isset($credentials['password'])) {
            return [
                'username' => $credentials['username'],
                'password' => $credentials['password']
            ];
        }
    }

    return [
        'username' => 'admin',
        'password' => 'admin'
    ];
}


/**
 * Validates the Turtle data against SHACL shapes in GraphDB.
 * This method prepares and executes a SPARQL query to validate the data against predefined SHACL shapes.
 * @param string $data The Turtle data to validate
 * @param string $graphUri The URI of the graph to validate against
 * @return array An array of validation errors, if any
 */
private function validateData($data, $graphUri)
    {
        $errors = [];
        $logger = new Logger(); 
        $writer = new Stream(OMEKA_PATH . '/logs/graphdb-errors.log');
        $logger->addWriter($writer);

        try {

            $credentials = $this->getGraphDBCredentials();

            $query = "PREFIX sh: <http://www.w3.org/ns/shacl#>
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            
            SELECT ?message
            WHERE {
              GRAPH <http://rdf4j.org/schema/rdf4j#SHACLShapeGraph> {
                ?shape a sh:NodeShape .
              }
              GRAPH <$graphUri> {
                ?focusNode ?predicate ?object .
              }
              FILTER EXISTS {
                  GRAPH <http://rdf4j.org/schema/rdf4j#SHACLShapeGraph> {
                    ?shape sh:targetClass ?targetClass .
                    FILTER NOT EXISTS { ?focusNode a ?targetClass }
                  }
              }
              FILTER EXISTS {
                  GRAPH <http://rdf4j.org/schema/rdf4j#SHACLShapeGraph> {
                    ?shape sh:property ?propertyShape .
                    ?propertyShape sh:path ?path .
                    FILTER NOT EXISTS { ?focusNode ?path ?object }
                  }
              }
              BIND(CONCAT('Violation at node: ', str(?focusNode), ', predicate: ', str(?predicate), ', object: ', str(?object)) AS ?message)
            }
            ";

            $client = new Client();
            $client->setUri($this->graphdbQueryEndpoint);
            $client->setMethod('POST');
            $client->setHeaders([
                'Content-Type' => 'application/sparql-query',
                'Accept' => 'application/sparql-results+json', 
                'Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['password'])
            ]);
            $client->setRawBody($query);
            $response = $client->send();
            if ($response->getStatusCode() == 401) {
                $errorMessage = "Authentication failed with GraphDB. Please check your credentials.";
                $logger->err($errorMessage);
   
                return [$errorMessage];
            }

            if (!$response->isSuccess()) {
                $errorMessage = "SHACL validation query failed: " . $response->getStatusCode() . " - " . $response->getBody();
                $logger->err($errorMessage);
   
                return [$errorMessage];
            }

            $rawBody = $response->getBody();
            $results = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMessage = "Error decoding JSON response: " . json_last_error_msg() . " Raw Body: " . $rawBody; 
                $logger->err($errorMessage);
   
                return [$errorMessage];
            }

            if (isset($results['results']['bindings'])) {
                foreach ($results['results']['bindings'] as $binding) {
                    $errors[] = $binding['message']['value'];
                }
            }
        } catch (\Exception $e) {
            $errorMessage = 'SHACL validation failed due to an exception: ' . $e->getMessage();
            $logger->err($errorMessage);
   
            return [$errorMessage];
        }

        return $errors;
    }



/** * Retrieves the excavation identifier from the item set ID.
 * This method checks site settings for existing mappings and attempts to extract the identifier from the item set title if no mapping exists.
 * @param int $itemSetId The ID of the item set
 * @return string|null The excavation identifier or null if not found
 */
private function getExcavationIdentifierFromItemSet($itemSetId)
{
    $mappings = $this->siteSettings()->get('excavation_itemset_mappings', []);
    
    if (isset($mappings[$itemSetId])) {
        return $mappings[$itemSetId];
    }
    
    // If no mapping found try to extract from item set title
    try {
        $itemSet = $this->api()->read('item_sets', $itemSetId)->getContent();
        $title = $itemSet->displayTitle();
        
        if (preg_match('/Excavation\s+([^\s]+)/', $title, $matches)) {
            $excavationId = $matches[1];
            
            $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationId);
            
            return $excavationId;
        }
    } catch (\Exception $e) {
   
    }
    
    return null;
}




/** Transforms Turtle data into Omeka S item data.
 * This method processes the Turtle data, extracts relevant information, and prepares it for Omeka S.
 * @param string $ttlData The Turtle data to transform
 * @param int|null $itemSetId The ID of the item set to associate with the items
 * @return array An array of Omeka S item data ready for upload
 */
private function transformTtlToOmekaSData($ttlData, $itemSetId = null): array {
   
    $graph = new \EasyRdf\Graph();
    $graph->parse($ttlData, 'turtle');
    
    $omekaData = [];
    $rdfData = $graph->toRdfPhp();

   
    
    // Find main subjects 
    $mainSubjects = $this->identifyMainSubjects($rdfData, $itemSetId);
    
    // Get excavation identifier
    $excavationId = "0"; 
    if ($itemSetId) {
        $mappedId = $this->getExcavationIdentifierFromItemSet($itemSetId);
        if ($mappedId) {
            $excavationId = $mappedId;
        }
    }
    
    // Process each  subject as a separate item
    foreach ($mainSubjects as $subject => $subjectType) {
        $itemData = [
            'o:resource_class' => ['o:id' => 1], 
            'o:item_set' => [],
        ];
        
        if ($itemSetId) {
            $itemData['o:item_set'][] = ['o:id' => $itemSetId];
        }
        
        $identifier = $this->extractIdentifier($rdfData, $subject);
        if ($identifier) {
            $itemData['dcterms:identifier'] = [
                [
                    'type' => 'literal',
                    'property_id' => 10,
                    '@value' => $identifier
                ]
            ];
        }
        
        $itemType = $this->determineItemType($subjectType);
        $title = $itemType;
        if ($identifier) {
            $title .= " " . $identifier;
            if ($excavationId != "0") {
                $title .= " (Excavation $excavationId)";
            }
        }
        
        $itemData['dcterms:title'] = [
            [
                'type' => 'literal',
                'property_id' => 1,
                '@value' => $title
            ]
        ];
        
        $this->extractCommonProperties($rdfData, $subject, $itemData);
   
        switch ($subjectType) {
            case 'arrowhead':
            case 'item':
                $this->processArrowheadData($rdfData, $subject, $itemData);
                break;
            case 'excavation':
   
                $this->processExcavationData($rdfData, $subject, $itemData);
                break;
            case 'context':
                $this->processContextData($rdfData, $subject, $itemData);
                break;
            case 'svu':
                $this->processSVUData($rdfData, $subject, $itemData);
                break;
            case 'square':
                $this->processSquareData($rdfData, $subject, $itemData);
                break;
        }
        
        $omekaData[] = $itemData;
    }

   
    
    return $omekaData;
}

/**
 * Method to check if the subject is a main arrowhead item.
 * @param mixed $rdfData
 * @param mixed $subject
 * @return bool
 */
private function isMainArrowheadItem($rdfData, $subject) {
    if (!isset($rdfData[$subject])) {
        return false;
    }
    
    $predicates = $rdfData[$subject];
    
    $arrowheadProperties = [
        'https://purl.org/megalod/ms/ah/shape',
        'ah:shape',
        'https://purl.org/megalod/ms/ah/variant', 
        'ah:variant',
        'https://purl.org/megalod/ms/ah/hasMorphology',
        'ah:hasMorphology'
    ];
    
    foreach ($arrowheadProperties as $prop) {
        if (isset($predicates[$prop])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Checks if the subject is a new encounter event.
 * This method verifies if the subject has properties indicating a real encounter event.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to check
 * @return bool True if it's a new encounter event, false otherwise
 */
private function isNewEncounterEvent($rdfData, $subject) {
    if (!isset($rdfData[$subject])) {
        return false;
    }
    
    $predicates = $rdfData[$subject];
    
   
    $encounterProperties = [
        'https://cidoc-crm.org/extensions/crmsci/O19_encountered_object',
        'crmsci:O19_encountered_object'
    ];
    
    foreach ($encounterProperties as $prop) {
        if (isset($predicates[$prop])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Identifies the main subjects in the RDF data.
 * This method checks for specific patterns in the RDF data to determine the main subjects.
 * @param mixed $rdfData The RDF data array
 * @param int|null $itemSetId The ID of the item set, if available
 * @return array An associative array of main subjects and their types
 */
private function identifyMainSubjects($rdfData, $itemSetId = null) {
    $subjects = [];
    
   
    $mainSubjectTypes = [
        'https://purl.org/megalod/ms/ah/Arrowhead' => 'arrowhead',
        'https://purl.org/megalod/ms/excavation/Item' => 'item',
        'https://purl.org/megalod/ms/excavation/Excavation' => 'excavation',
        'https://purl.org/megalod/ms/excavation/Context' => 'context',
        'https://purl.org/megalod/ms/excavation/StratigraphicVolumeUnit' => 'svu',
        'https://purl.org/megalod/ms/excavation/Square' => 'square',
        'excav:Excavation' => 'excavation',
        'excav:Context' => 'context',
        'ah:Arrowhead' => 'arrowhead',
        'excav:Item' => 'item',
        'excav:StratigraphicVolumeUnit' => 'svu',
        'excav:Square' => 'square',
    ];
    
    if ($itemSetId) {
        $normalizedPatterns = [
            "http://localhost/megalod/$itemSetId/ah/Arrowhead" => 'arrowhead',
            "http://localhost/megalod/$itemSetId/excavation/Item" => 'item', 
            "http://localhost/megalod/$itemSetId/excavation/Excavation" => 'excavation',
            "http://localhost/megalod/$itemSetId/excavation/Context" => 'context',
            "http://localhost/megalod/$itemSetId/excavation/StratigraphicVolumeUnit" => 'svu',
            "http://localhost/megalod/$itemSetId/excavation/Square" => 'square',
        ];
        
        $mainSubjectTypes = array_merge($mainSubjectTypes, $normalizedPatterns);
        
   
    }
    
    $excludedTypes = [
        'https://purl.org/megalod/ms/excavation/Location',
        'https://purl.org/megalod/ms/excavation/GPSCoordinates',
        'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#Location',
        'http://dbpedia.org/ontology/Location',
        'excav:Location',
        'excav:GPSCoordinates',
        'https://purl.org/megalod/ms/excavation/Archaeologist',
        'excav:Archaeologist',
        'https://purl.org/megalod/ms/excavation/TimeLine',
        'https://purl.org/megalod/ms/excavation/Instant',
        'excav:TimeLine',
        'excav:Instant',
        'http://dbpedia.org/ontology/District',
        'http://dbpedia.org/ontology/Parish',
    ];
    
    if ($itemSetId) {
        $excludedTypes = array_merge($excludedTypes, [
            "http://localhost/megalod/$itemSetId/excavation/Location",
            "http://localhost/megalod/$itemSetId/excavation/GPSCoordinates", 
            "http://localhost/megalod/$itemSetId/excavation/Archaeologist",
            "http://localhost/megalod/$itemSetId/excavation/TimeLine",
            "http://localhost/megalod/$itemSetId/excavation/Instant",
        ]);
    }
    
    $hasExcavationInData = false;
    $hasArrowheadsInData = false;
    
    foreach ($rdfData as $subject => $predicates) {
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                if ($typeObj['type'] === 'uri') {
                    if ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Excavation' ||
                        $typeObj['value'] === 'excav:Excavation' ||
                        ($itemSetId && $typeObj['value'] === "http://localhost/megalod/$itemSetId/excavation/Excavation")) {
                        $hasExcavationInData = true;
                    }
                    
                    // Check for arrowheads
                    if ($typeObj['value'] === 'https://purl.org/megalod/ms/ah/Arrowhead' ||
                        $typeObj['value'] === 'ah:Arrowhead' ||
                        ($itemSetId && $typeObj['value'] === "http://localhost/megalod/$itemSetId/ah/Arrowhead")) {
                        $hasArrowheadsInData = true;
                    }
                }
            }
        }
    }
    
    $isCompleteExcavationUpload = $hasExcavationInData && !$hasArrowheadsInData;
    $isArrowheadOnlyUpload = $hasArrowheadsInData && !$hasExcavationInData;
        
   
   

    if ($itemSetId && !$isCompleteExcavationUpload) {
   

        $arrowheadSubjects = [];
        foreach ($rdfData as $subject => $predicates) {
            if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri') {
                        if ($typeObj['value'] === 'https://purl.org/megalod/ms/ah/Arrowhead' ||
                            $typeObj['value'] === 'ah:Arrowhead' ||
                            ($itemSetId && $typeObj['value'] === "http://localhost/megalod/$itemSetId/ah/Arrowhead")) {
                            
                            $arrowheadSubjects[$subject] = 'arrowhead';
   
                        }
                        else if (($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Item' ||
                                $typeObj['value'] === 'excav:Item' ||
                                ($itemSetId && $typeObj['value'] === "http://localhost/megalod/$itemSetId/excavation/Item")) &&
                                $this->isMainArrowheadItem($rdfData, $subject)) {
                            
                            $arrowheadSubjects[$subject] = 'item';
   
                        }
                    }
                }
            }
        }
        
        foreach ($rdfData as $subject => $predicates) {
            if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/EncounterEvent' ||
                         $typeObj['value'] === 'excav:EncounterEvent')) {
                        
                        if ($this->isNewEncounterEvent($rdfData, $subject)) {
                            $arrowheadSubjects[$subject] = 'encounter';
   
                        }
                    }
                }
            }
        }
        
   
        return $arrowheadSubjects;
    }

    
    foreach ($rdfData as $subject => $predicates) {
        if (isset($subjects[$subject]) && $subjects[$subject] === 'excluded') {
            continue;
        }

   
        
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
   
                
                if ($typeObj['type'] === 'uri' && 
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/EncounterEvent' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/ah/Morphology' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/ah/Chipping' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/TypometryValue' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/Weight' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/Coordinates' &&
                    !in_array($typeObj['value'], $excludedTypes) &&     
                    isset($mainSubjectTypes[$typeObj['value']])) {
                    
                    $subjects[$subject] = $mainSubjectTypes[$typeObj['value']];
   
                    break; 
                }
                
                if (in_array($typeObj['value'], $excludedTypes)) {
                    $subjects[$subject] = 'excluded';
   
                    break;
                }
            }
        }
    }
    
    $subjects = array_filter($subjects, function($type) {
        return $type !== 'excluded';
    });
    
   
    
    return $subjects;
}


/**
 * Retrieves location data from the excavation item set.
 * This method queries the GraphDB for location information associated with the excavation.
 * @param int $itemSetId The ID of the item set
 * @return array|null An associative array with location details or null if not found
 */
private function getLocationDataFromExcavation($itemSetId) {
    try {
        $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
        
        $query = "
        PREFIX excav: <https://purl.org/megalod/ms/excavation/>
        PREFIX dbo: <http://dbpedia.org/ontology/>
        PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
        PREFIX dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#>
        
        SELECT ?locationUri ?locationName ?district ?parish ?country ?lat ?long
        WHERE {
          GRAPH <$graphUri> {
            ?excavation a excav:Excavation ;
                        dul:hasLocation ?locationUri .
            
            OPTIONAL { ?locationUri dbo:informationName ?locationName }
            OPTIONAL { ?locationUri dbo:district ?district }
            OPTIONAL { ?locationUri dbo:parish ?parish }
            OPTIONAL { ?locationUri dbo:Country ?country }
            OPTIONAL { ?locationUri geo:lat ?lat }
            OPTIONAL { ?locationUri geo:long ?long }
          }
        }
        LIMIT 1";
        
        $results = $this->querySparql($query);
        
        if (!empty($results)) {
            $result = $results[0];
            return [
                'uri' => $result['locationUri']['value'],
                'name' => isset($result['locationName']) ? $result['locationName']['value'] : null,
                'district' => isset($result['district']) ? $result['district']['value'] : null,
                'parish' => isset($result['parish']) ? $result['parish']['value'] : null,
                'country' => isset($result['country']) ? $result['country']['value'] : null,
                'lat' => isset($result['lat']) ? $result['lat']['value'] : null,
                'long' => isset($result['long']) ? $result['long']['value'] : null
            ];
        }
    } catch (\Exception $e) {
   
    }
    
    return null;
}

/**
 * Extracts the identifier from the RDF data for a given subject.
 * This method looks for the 'dcterms:identifier' property and returns its value.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract the identifier from
 * @return string|null The identifier value or null if not found
 */
private function extractIdentifier($rdfData, $subject) {
    if (isset($rdfData[$subject]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$subject]['http://purl.org/dc/terms/identifier'] as $idObj) {
            if ($idObj['type'] === 'literal') {
                return $idObj['value'];
            }
        }
    }
    return null;
}



/**
 * Processes the RDF data for an arrowhead item.
 * This method extracts various properties and measurements related to the arrowhead.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI of the arrowhead
 * @param array &$itemData The item data array to populate with extracted information
 */
private function processArrowheadData($rdfData, $subject, &$itemData) {

    // Current item set context
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    // DIRECT ARROWHEAD PROPERTIES 
    $this->extractDirectArrowheadProperties($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 2. MEASUREMENTS 
    $this->extractAllMeasurements($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 3. MORPHOLOGY DATA 
    $this->extractCompleteMorphologyData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 4. CHIPPING DATA   
    $this->extractCompleteChippingData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 5. COORDINATES
    $this->extractCoordinateDataEnhanced($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 6. ENCOUNTER EVENT
    $this->extractEncounterEventData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 7. ARCHAEOLOGICAL CONTEXT 
    $this->extractArchaeologicalContext($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 8. IMAGES/MEDIA
    $this->extractMediaResources($rdfData, $subject, $itemData);
    
    // 9. GPS COORDINATES
    $this->extractGPSCoordinates($rdfData, $subject, $itemData, $currentItemSetId);
   
}


/**
 * Extracts GPS coordinates from the RDF data for a given subject.
 * This method looks for the 'hasGPSCoordinates' property and retrieves latitude and longitude values.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract GPS coordinates from
 * @param array &$itemData The item data array to populate with GPS coordinates
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractGPSCoordinates($rdfData, $subject, &$itemData, $currentItemSetId) {
    $gpsPropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasGPSCoordinates',
        'excav:hasGPSCoordinates'
    ];
    
    if ($currentItemSetId) {
        $gpsPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasGPSCoordinates";
    }
    
    foreach ($gpsPropertyUris as $gpsPropertyUri) {
        if (isset($rdfData[$subject][$gpsPropertyUri])) {
            foreach ($rdfData[$subject][$gpsPropertyUri] as $gpsObj) {
                if ($gpsObj['type'] === 'uri' && isset($rdfData[$gpsObj['value']])) {
                    $gpsUri = $gpsObj['value'];
                    $coordinates = [];
                    
                    if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
                        $coordinates['lat'] = $rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'][0]['value'];
                    }
                    
                    if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
                        $coordinates['long'] = $rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'][0]['value'];
                    }
                    
                    if (!empty($coordinates)) {
                        $coordStr = "Lat: {$coordinates['lat']}, Long: {$coordinates['long']}";
                        
                        $itemData['GPS Coordinates'][] = [
                            'type' => 'literal',
                            'property_id' => 7664,
                            '@value' => $coordStr
                        ];
                        
   
                        return;
                    }
                }
            }
        }
    }
}



/**
 * Extracts direct arrowhead properties from the RDF data.
 * This method retrieves properties like shape, variant, material, elongation index, and thickness index.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI of the arrowhead
 * @param array &$itemData The item data array to populate with extracted properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractDirectArrowheadProperties($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    $propertyVariations = [
        'shape' => [
            'https://purl.org/megalod/ms/ah/shape',
            'ah:shape',
            "http://localhost/megalod/$currentItemSetId/ah/shape" 
        ],
        'variant' => [
            'https://purl.org/megalod/ms/ah/variant', 
            'ah:variant',
            "http://localhost/megalod/$currentItemSetId/ah/variant" 
        ],
        'material' => [
            'http://www.cidoc-crm.org/cidoc-crm/E57_Material',
            'crm:E57_Material' 
        ],
        'elongationIndex' => [
            'https://purl.org/megalod/ms/excavation/elongationIndex',
            'excav:elongationIndex' 
        ],
        'thicknessIndex' => [
            'https://purl.org/megalod/ms/excavation/thicknessIndex',
            'excav:thicknessIndex'
        ]
    ];
    
    $propertyMappings = [
        'shape' => ['Arrowhead Shape', 7651],
        'variant' => ['Arrowhead Variant', 7652], 
        'material' => ['Material', 4633],
        'elongationIndex' => ['Elongation Index', 7676],
        'thicknessIndex' => ['Thickness Index', 7677]
    ];
    
    foreach ($propertyVariations as $propertyName => $uriVariations) {
        $found = false;
        
        foreach ($uriVariations as $uri) {
            if (isset($rdfData[$subject][$uri])) {
                $label = $propertyMappings[$propertyName][0];
                $propertyId = $propertyMappings[$propertyName][1];
                
                if (!isset($itemData[$label])) {
                    $itemData[$label] = [];
                }
                
                foreach ($rdfData[$subject][$uri] as $valueObj) {
                    $value = $this->extractPropertyValue($valueObj);
                    if ($value) {
                        $itemData[$label][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $value
                        ];
                        
   
                        $found = true;
                    }
                }
                break; 
            }
        }
    }
}


/**
 * Processes morphology data for an arrowhead item.
 * This method extracts properties related to the morphology of the arrowhead.
 * @param mixed $rdfData The RDF data array
 * @param mixed $morphologyUri The URI of the morphology resource
 * @param array &$itemData The item data array to populate with morphology properties
 */
private function processMorphologyResource($rdfData, $morphologyUri, &$itemData) {
   
    
    // Get current item set for URI normalization
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    $morphologyProperties = [
        'point' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/point', 
                'ah:point',
                "http://localhost/megalod/$currentItemSetId/ah/point" 
            ],
            'label' => 'Point Definition (Sharp/Fractured)',
            'propertyId' => 7653,
            'type' => 'boolean'
        ],
        'body' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/body', 
                'ah:body',
                "http://localhost/megalod/$currentItemSetId/ah/body" 
            ],
            'label' => 'Body Symmetry (Symmetrical/Non-symmetrical)',
            'propertyId' => 7654,
            'type' => 'boolean'
        ],
        'base' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/base', 
                'ah:base',
                "http://localhost/megalod/$currentItemSetId/ah/base" 
            ],
            'label' => 'Base Type',
            'propertyId' => 7655,
            'type' => 'uri'
        ]
    ];
    
    foreach ($morphologyProperties as $propName => $config) {
        foreach ($config['uris'] as $uri) {
            if (isset($rdfData[$morphologyUri][$uri])) {
                if (!isset($itemData[$config['label']])) {
                    $itemData[$config['label']] = [];
                }
                
                foreach ($rdfData[$morphologyUri][$uri] as $valueObj) {
                    $value = $this->extractPropertyValue($valueObj, $config['type']);
                    if ($value) {
                        $itemData[$config['label']][] = [
                            'type' => 'literal',
                            'property_id' => $config['propertyId'],
                            '@value' => $value
                        ];
                        
   
                    }
                }
                break; 
            }
        }
    }
}

/**
 * Processes chipping data for an arrowhead item.
 * This method extracts properties related to the chipping characteristics of the arrowhead.
 * @param mixed $rdfData The RDF data array
 * @param mixed $chippingUri The URI of the chipping resource
 * @param array &$itemData The item data array to populate with chipping properties
 */
private function processChippingResource($rdfData, $chippingUri, &$itemData) {
   
    
    // Get current item set for URI normalization
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    $chippingProperties = [
        'chippingMode' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingMode', 
                'ah:chippingMode',
                "http://localhost/megalod/$currentItemSetId/ah/chippingMode" 
            ],
            'label' => 'Chipping Mode',
            'propertyId' => 7656,
            'type' => 'uri'
        ],
        'chippingAmplitude' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingAmplitude', 
                'ah:chippingAmplitude',
                "http://localhost/megalod/$currentItemSetId/ah/chippingAmplitude" 
            ],
            'label' => 'Chipping Amplitude (Marginal/Deep)',
            'propertyId' => 7657,
            'type' => 'boolean'
        ],
        'chippingDirection' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingDirection', 
                'ah:chippingDirection',
                "http://localhost/megalod/$currentItemSetId/ah/chippingDirection" 
            ],
            'label' => 'Chipping Direction',
            'propertyId' => 7658,
            'type' => 'uri'
        ],
        'chippingOrientation' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingOrientation', 
                'ah:chippingOrientation',
                "http://localhost/megalod/$currentItemSetId/ah/chippingOrientation" 
            ],
            'label' => 'Chipping Orientation (Lateral/Transverse)',
            'propertyId' => 7659,
            'type' => 'boolean'
        ],
        'chippingDelineation' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingDelineation', 
                'ah:chippingDelineation',
                "http://localhost/megalod/$currentItemSetId/ah/chippingDelineation" 
            ],
            'label' => 'Chipping Delineation',
            'propertyId' => 7660,
            'type' => 'uri'
        ],
        'chippingLocationSide' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingLocationSide', 
                'ah:chippingLocationSide',
                "http://localhost/megalod/$currentItemSetId/ah/chippingLocationSide" 
            ],
            'label' => 'Chipping Location Side',
            'propertyId' => 7662,
            'type' => 'uri_multiple'
        ],
        'chippingLocationTransversal' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingLocationTransversal', 
                'ah:chippingLocationTransversal',
                "http://localhost/megalod/$currentItemSetId/ah/chippingLocationTransversal" 
            ],
            'label' => 'Chipping Location Transversal',
            'propertyId' => 7663,
            'type' => 'uri_multiple'
        ],
        'chippingShape' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/chippingShape', 
                'ah:chippingShape',
                "http://localhost/megalod/$currentItemSetId/ah/chippingShape" 
            ],
            'label' => 'Chipping Shape',
            'propertyId' => 7661,
            'type' => 'uri'
        ]
    ];
    
    foreach ($chippingProperties as $propName => $config) {
        foreach ($config['uris'] as $uri) {
            if (isset($rdfData[$chippingUri][$uri])) {
                if (!isset($itemData[$config['label']])) {
                    $itemData[$config['label']] = [];
                }
                
                foreach ($rdfData[$chippingUri][$uri] as $valueObj) {
                    $value = $this->extractPropertyValue($valueObj, $config['type']);
                    if ($value) {
                        $itemData[$config['label']][] = [
                            'type' => 'literal',
                            'property_id' => $config['propertyId'],
                            '@value' => $value
                        ];
                        
   
                    }
                }
                break;
            }
        }
    }
}

/**
 * Extracts the value from a property value object.
 * This method handles both literal and URI types, converting boolean values to specific strings if needed.
 * @param array $valueObj The property value object
 * @param string $type The type of the property (default is 'auto')
 * @return mixed The extracted value or null if not applicable
 */
private function extractPropertyValue($valueObj, $type = 'auto') {
    if ($valueObj['type'] === 'literal') {
        $value = $valueObj['value'];
        
        if ($type === 'boolean' || $value === 'true' || $value === 'false') {
            if ($value === 'true') {
                switch ($type) {
                    case 'morphology_point':
                        return 'Sharp';
                    case 'morphology_body':
                        return 'Symmetrical';
                    case 'chipping_amplitude':
                        return 'Marginal';
                    case 'chipping_orientation':
                        return 'Lateral';
                    default:
                        return 'True';
                }
            } elseif ($value === 'false') {
                switch ($type) {
                    case 'morphology_point':
                        return 'Fractured';
                    case 'morphology_body':
                        return 'Non-symmetrical';
                    case 'chipping_amplitude':
                        return 'Deep';
                    case 'chipping_orientation':
                        return 'Transverse';
                    default:
                        return 'False';
                }
            }
        }
        
        return $value;
    } elseif ($valueObj['type'] === 'uri') {
        if (strpos($valueObj['value'], '/kos/') !== false || 
            strpos($valueObj['value'], '/ah-') !== false ||
            strpos($valueObj['value'], '/MegaLOD-') !== false) {
            $parts = explode('/', $valueObj['value']);
            $lastPart = end($parts);
            
            if (strpos($lastPart, 'ah-') === 0) {
                $clean = substr($lastPart, 3); 
            } elseif (strpos($lastPart, 'MegaLOD-Index') === 0) {
                $clean = str_replace('MegaLOD-Index', '', $lastPart);
            } else {
                $clean = $lastPart;
            }
            
            return str_replace('-', ' ', $clean);
        } else {
            $parts = explode('/', $valueObj['value']);
            return end($parts);
        }
    }
    
    return null;
}

/**
 * Extracts all measurements from the RDF data for a given subject.
 * This method looks for specific measurement properties and retrieves their values and units.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract measurements from
 * @param array &$itemData The item data array to populate with measurement properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractAllMeasurements($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    $measurementProperties = [
        'height' => [
            'uris' => [
                'http://schema.org/height', 
                'schema:height'
            ],
            'label' => 'Height',
            'propertyId' => 5616
        ],
        'width' => [
            'uris' => [
                'http://schema.org/width', 
                'schema:width'
            ],
            'label' => 'Width', 
            'propertyId' => 5688
        ],
        'depth' => [
            'uris' => [
                'http://schema.org/depth', 
                'schema:depth'
            ],
            'label' => 'Thickness',
            'propertyId' => 7244
        ],
        'weight' => [
            'uris' => [
                'http://schema.org/weight', 
                'schema:weight'
            ],
            'label' => 'Weight',
            'propertyId' => 5779
        ],
        'bodyLength' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/hasBodyLength', 
                'ah:hasBodyLength',
                "http://localhost/megalod/$currentItemSetId/ah/hasBodyLength" 
            ],
            'label' => 'Body Length',
            'propertyId' => 7678
        ],
        'baseLength' => [
            'uris' => [
                'https://purl.org/megalod/ms/ah/hasBaseLength', 
                'ah:hasBaseLength',
                "http://localhost/megalod/$currentItemSetId/ah/hasBaseLength" 
            ],
            'label' => 'Base Length', 
            'propertyId' => 7679
        ]
    ];
    
    foreach ($measurementProperties as $measurementName => $config) {
        $found = false;
        
        foreach ($config['uris'] as $uri) {
            if (isset($rdfData[$subject][$uri])) {
   
                
                foreach ($rdfData[$subject][$uri] as $measObj) {
                    if ($measObj['type'] === 'uri' && isset($rdfData[$measObj['value']])) {
                        $measurementUri = $measObj['value'];
   
                        
                        $value = $this->extractMeasurementValue($rdfData, $measurementUri);
                        $unit = $this->extractMeasurementUnit($rdfData, $measurementUri);
                        
                        if ($value !== null) {
                            $displayValue = $value;
                            if ($unit) {
                                $cleanUnit = str_replace(['<', '>'], '', $unit);
                                $displayValue .= " " . $cleanUnit;
                            }
                            
                            if (!isset($itemData[$config['label']])) {
                                $itemData[$config['label']] = [];
                            }
                            
                            $itemData[$config['label']][] = [
                                'type' => 'literal',
                                'property_id' => $config['propertyId'],
                                '@value' => $displayValue
                            ];
                            
   
                            $found = true;
                        }
                    }
                }
                break;
            }
        }
        
        if (!$found) {
   
        }
    }
}


/**
 * Extracts complete morphology data from the RDF data for a given subject.
 * This method looks for morphology-related properties and processes them.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract morphology data from
 * @param array &$itemData The item data array to populate with morphology properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractCompleteMorphologyData($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    $morphologyUris = [
        'https://purl.org/megalod/ms/ah/hasMorphology',
        'ah:hasMorphology'
    ];
    
    if ($currentItemSetId) {
        $morphologyUris[] = "http://localhost/megalod/$currentItemSetId/ah/hasMorphology";
    }
    
    $morphologyFound = false;
    
    // Find via hasMorphology property
    foreach ($morphologyUris as $morphologyUri) {
        if (isset($rdfData[$subject][$morphologyUri])) {
            foreach ($rdfData[$subject][$morphologyUri] as $morphObj) {
                if ($morphObj['type'] === 'uri' && isset($rdfData[$morphObj['value']])) {
                    $this->processMorphologyResource($rdfData, $morphObj['value'], $itemData);
                    $morphologyFound = true;
                }
            }
        }
    }
    
    // Scan ALL resources for morphology types
    if (!$morphologyFound) {
   
        
        foreach ($rdfData as $resourceUri => $properties) {
            if (isset($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        (strpos($typeObj['value'], 'Morphology') !== false ||
                         $typeObj['value'] === 'https://purl.org/megalod/ms/ah/Morphology' ||
                         $typeObj['value'] === 'ah:Morphology')) {
                        
   
                        $this->processMorphologyResource($rdfData, $resourceUri, $itemData);
                        $morphologyFound = true;
                    }
                }
            }
        }
    }
    
    if (!$morphologyFound) {
   
    }
}

/**
 * Extracts complete chipping data from the RDF data for a given subject.
 * This method looks for chipping-related properties and processes them.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract chipping data from
 * @param array &$itemData The item data array to populate with chipping properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractCompleteChippingData($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    // First try to find chipping via hasChipping property
    $chippingUris = [
        'https://purl.org/megalod/ms/ah/hasChipping',
        'ah:hasChipping'
    ];
    
    if ($currentItemSetId) {
        $chippingUris[] = "http://localhost/megalod/$currentItemSetId/ah/hasChipping";
    }
    
    $chippingFound = false;
    
    foreach ($chippingUris as $chippingUri) {
        if (isset($rdfData[$subject][$chippingUri])) {
            foreach ($rdfData[$subject][$chippingUri] as $chipObj) {
                if ($chipObj['type'] === 'uri' && isset($rdfData[$chipObj['value']])) {
                    $this->processChippingResource($rdfData, $chipObj['value'], $itemData);
                    $chippingFound = true;
                }
            }
        }
    }
    
    if (!$chippingFound) {
   
        
        foreach ($rdfData as $resourceUri => $properties) {
            if (isset($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        (strpos($typeObj['value'], 'Chipping') !== false ||
                         $typeObj['value'] === 'https://purl.org/megalod/ms/ah/Chipping' ||
                         $typeObj['value'] === 'ah:Chipping')) {
                        
   
                        $this->processChippingResource($rdfData, $resourceUri, $itemData);
                        $chippingFound = true;
                    }
                }
            }
        }
    }
    
    if (!$chippingFound) {
   
    }
}

/**
 * Extracts coordinate data from the RDF data for a given subject.
 * This method looks for coordinate-related properties and processes them.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract coordinate data from
 * @param array &$itemData The item data array to populate with coordinate properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractCoordinateData($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    $coordinateUris = [
        'https://purl.org/megalod/ms/excavation/hasCoordinatesInSquare',
        'excav:hasCoordinatesInSquare'
    ];
    
    if ($currentItemSetId) {
        $coordinateUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasCoordinatesInSquare";
    }
    
    $coordinatesFound = false;
    
    foreach ($coordinateUris as $coordinateUri) {
        if (isset($rdfData[$subject][$coordinateUri])) {
            foreach ($rdfData[$subject][$coordinateUri] as $coordObj) {
                if ($coordObj['type'] === 'uri' && isset($rdfData[$coordObj['value']])) {
                    $this->processCoordinateResource($rdfData, $coordObj['value'], $itemData);
                    $coordinatesFound = true;
                }
            }
        }
    }
    
    if (!$coordinatesFound) {
   
        
        foreach ($rdfData as $resourceUri => $properties) {
            if (isset($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($properties['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        (strpos($typeObj['value'], 'Coordinates') !== false ||
                         $typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Coordinates' ||
                         $typeObj['value'] === 'excav:Coordinates')) {
                        
   
                        $this->processCoordinateResource($rdfData, $resourceUri, $itemData);
                        $coordinatesFound = true;
                    }
                }
            }
        }
    }
    
    if (!$coordinatesFound) {
   
    }
}



/**
 * Processes coordinate data for an arrowhead item.
 * This method extracts coordinates from the RDF data and formats them for display.
 * @param mixed $rdfData The RDF data array
 * @param mixed $coordinateUri The URI of the coordinate resource
 * @param array &$itemData The item data array to populate with coordinate properties
 */
private function processCoordinateResource($rdfData, $coordinateUri, &$itemData) {
   
    
    $coordinates = [
        'X' => null,
        'Y' => null,
        'Z' => null
    ];
    
    if (isset($rdfData[$coordinateUri]['http://schema.org/value'])) {
        $values = $rdfData[$coordinateUri]['http://schema.org/value'];
   
        
        foreach ($values as $index => $valueObj) {
            if ($valueObj['type'] === 'literal') {
                $key = isset(['X', 'Y', 'Z'][$index]) ? ['X', 'Y', 'Z'][$index] : "Value$index";
                $coordinates[$key] = $valueObj['value'];
   
            }
        }
    }

    $coordinateProps = [
        'http://www.w3.org/2003/01/geo/wgs84_pos#long' => 'X',
        'http://www.w3.org/2003/01/geo/wgs84_pos#lat' => 'Y',
        'http://schema.org/depth' => 'Z',
        'geo:long' => 'X',
        'geo:lat' => 'Y'
    ];
    
    foreach ($coordinateProps as $propUri => $coord) {
        if (isset($rdfData[$coordinateUri][$propUri])) {
            foreach ($rdfData[$coordinateUri][$propUri] as $valueObj) {
                if ($valueObj['type'] === 'uri' && isset($rdfData[$valueObj['value']])) {
                    $typometryUri = $valueObj['value'];
   
                    
                    $value = $this->extractMeasurementValue($rdfData, $typometryUri);
                    $unit = $this->extractMeasurementUnit($rdfData, $typometryUri);
                    
                    if ($value !== null) {
                        $coordinates[$coord] = $value . ($unit ? " $unit" : "");
   
                    }
                } else if ($valueObj['type'] === 'literal') {
                    $coordinates[$coord] = $valueObj['value'];
   
                }
            }
        }
    }
    
    $coordStrings = [];
    foreach ($coordinates as $axis => $value) {
        if ($value !== null) {
            $coordStrings[] = "$axis: $value";
        }
    }
    
    if (!empty($coordStrings)) {
        if (!isset($itemData['Coordinates'])) {
            $itemData['Coordinates'] = [];
        }
        
        $coordDisplay = implode(', ', $coordStrings);
        $itemData['Coordinates'][] = [
            'type' => 'literal',
            'property_id' => 7674,
            '@value' => $coordDisplay
        ];
        
   
    } else {
   
    }
}

/**
 * Processes encounter event data for an arrowhead item.
 * This method extracts encounter dates and encountered objects from the RDF data.
 * @param mixed $rdfData The RDF data array
 * @param mixed $encounterUri The URI of the encounter event resource
 * @param array &$itemData The item data array to populate with encounter properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function processEncounterEvent($rdfData, $encounterUri, &$itemData, $currentItemSetId) {
   
    if (isset($rdfData[$encounterUri]['http://purl.org/dc/terms/date'])) {
        foreach ($rdfData[$encounterUri]['http://purl.org/dc/terms/date'] as $dateObj) {
            if ($dateObj['type'] === 'literal') {
                if (!isset($itemData['Encounter Date'])) {
                    $itemData['Encounter Date'] = [];
                }
                
                $itemData['Encounter Date'][] = [
                    'type' => 'literal',
                    'property_id' => 7675,
                    '@value' => $dateObj['value']
                ];
                
   
            }
        }
    }
    
    $encounteredObjects = [];
    $encounteredItemUris = [];

    if (isset($rdfData[$encounterUri]['crmsci:O19_encountered_object'])) {
    $encounteredRefs = [];
    
    foreach ($rdfData[$encounterUri]['crmsci:O19_encountered_object'] as $objRef) {
        if ($objRef['type'] === 'uri') {
            $objId = $this->extractIdentifierFromUri($objRef['value']) ?: basename($objRef['value']);
            $displayValue = $this->extractResourceDisplayName($rdfData, $objRef['value']) ?: $objId;
            
            $encounteredRefs[] = $displayValue;
            
            if (!isset($itemData['Encountered Item'])) {
                $itemData['Encountered Item'] = [];
            }
            
            $itemData['Encountered Item'][] = [
                'type' => 'uri',
                'property_id' => 374, 
                '@id' => $objRef['value'],
                'o:label' => $displayValue
            ];
        }
    }
    
    if (!empty($encounteredRefs)) {
        if (!isset($itemData['Encountered Objects'])) {
            $itemData['Encountered Objects'] = [];
        }
        
        $itemData['Encountered Objects'][] = [
            'type' => 'literal', 
            'property_id' => 374,
            '@value' => implode(', ', $encounteredRefs)
        ];
    }
}
    
    if (isset($rdfData[$encounterUri]['https://cidoc-crm.org/extensions/crmsci/O19_encountered_object']) || 
        isset($rdfData[$encounterUri]['crmsci:O19_encountered_object'])) {
        
        $propertyVariations = [
            'https://cidoc-crm.org/extensions/crmsci/O19_encountered_object',
            'crmsci:O19_encountered_object'
        ];
        
        if ($currentItemSetId) {
            $propertyVariations[] = "http://localhost/megalod/$currentItemSetId/crmsci/O19_encountered_object";
        }
        
        foreach ($propertyVariations as $propertyUri) {
            if (isset($rdfData[$encounterUri][$propertyUri])) {
                foreach ($rdfData[$encounterUri][$propertyUri] as $itemObj) {
                    if ($itemObj['type'] === 'uri') {
                        $itemUri = $itemObj['value'];
                        $encounteredItemUris[] = $itemUri;
                        
                        $itemId = null;
                        $identifier = $this->extractIdentifierFromUri($itemUri);
                        
                        if (isset($rdfData[$itemUri]) && 
                            isset($rdfData[$itemUri]['http://purl.org/dc/terms/identifier'])) {
                            foreach ($rdfData[$itemUri]['http://purl.org/dc/terms/identifier'] as $idObj) {
                                if ($idObj['type'] === 'literal') {
                                    $identifier = $idObj['value'];
                                }
                            }
                        }
                        
                        $encounteredObjects[] = [
                            'identifier' => $identifier ?: basename($itemUri),
                            'uri' => $itemUri
                        ];
                    }
                }
            }
        }
    }
    
    if (!empty($encounteredObjects)) {
        if (!isset($itemData['Encountered Objects'])) {
            $itemData['Encountered Objects'] = [];
        }
        
        $identifierList = array_map(function($obj) { 
            return $obj['identifier']; 
        }, $encounteredObjects);
        
        $itemData['Encountered Objects'][] = [
            'type' => 'literal',
            'property_id' => 374,
            '@value' => implode(', ', $identifierList)
        ];
        
   
        
        if (!isset($itemData['Encountered Item'])) {
            $itemData['Encountered Item'] = [];
        }
        
        foreach ($encounteredObjects as $encObj) {
            $identifier = $encObj['identifier'];
            $uri = $encObj['uri'];
            
            $item = $this->findItemByIdentifier($identifier, $currentItemSetId);
            
            if ($item) {
                $itemData['Encountered Item'][] = [
                    'type' => 'resource',
                    'property_id' => 374, 
                    'value_resource_id' => $item->id()
                ];
                
   
            } else {
                $itemData['Encountered Item'][] = [
                    'type' => 'uri',
                    'property_id' => 7686,
                    '@id' => $uri,
                    'o:label' => $identifier
                ];
                
   
            }
        }
    } else {
   
    }
    
    if (isset($rdfData[$encounterUri]['http://dbpedia.org/ontology/depth'])) {
        foreach ($rdfData[$encounterUri]['http://dbpedia.org/ontology/depth'] as $depthObj) {
            if ($depthObj['type'] === 'literal') {
                if (!isset($itemData['Discovery Depth'])) {
                    $itemData['Discovery Depth'] = [];
                }
                
                $itemData['Discovery Depth'][] = [
                    'type' => 'literal',
                    'property_id' => 7676,
                    '@value' => $depthObj['value']
                ];
                
   
            }
        }
    }
    
    $contextPatterns = [
        'excav:foundInExcavation' => [
            'label' => 'The Encounter Event - an item found in an Excavation',
            'propertyId' => 7673,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInExcavation',
                'excav:foundInExcavation'
            ]
        ],
        'excav:foundInLocation' => [
            'label' => 'Item found in a Location',
            'propertyId' => 7680,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInLocation',
                'excav:foundInLocation'
            ]
        ],
        'excav:foundInSquare' => [
            'label' => 'The Item found in a Square',
            'propertyId' => 7683,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInSquare',
                'excav:foundInSquare'
            ]
        ],
        'excav:foundInContext' => [
            'label' => 'The Encounter Event - an item found in a specific Context',
            'propertyId' => 7672,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInContext',
                'excav:foundInContext'
            ]
        ],
        'excav:foundInSVU' => [
            'label' => 'The Item found in a SVU',
            'propertyId' => 7671,
            'variantUris' => [
                'https://purl.org/megalod/ms/excavation/foundInSVU',
                'excav:foundInSVU'
            ]
        ]
    ];
    
    if ($currentItemSetId) {
        foreach ($contextPatterns as $key => $config) {
            $baseProperty = str_replace('excav:', '', $key);
            $contextPatterns[$key]['variantUris'][] = "http://localhost/megalod/$currentItemSetId/excavation/$baseProperty";
        }
    }
    
    foreach ($contextPatterns as $key => $config) {
        $found = false;
        
        foreach ($config['variantUris'] as $predicateUri) {
            if (isset($rdfData[$encounterUri][$predicateUri])) {
   
                
                foreach ($rdfData[$encounterUri][$predicateUri] as $contextObj) {
                    if ($contextObj['type'] === 'uri') {
                        $contextUri = $contextObj['value'];
                        $displayValue = $this->extractContextDisplayValue($rdfData, $contextUri) ?: $contextUri;
                        
                        if (!isset($itemData[$config['label']])) {
                            $itemData[$config['label']] = [];
                        }
                        
                        $itemData[$config['label']][] = [
                            'type' => 'uri',
                            'property_id' => $config['propertyId'],
                            '@id' => $contextUri,
                            'o:label' => $displayValue
                        ];
                        
   
                        $found = true;
                    }
                }
                
                if ($found) break; 
            }
        }
    }
    
   
}

/**
 * This method extracts the display name for a resource from the RDF data.
 * @param mixed $rdfData
 * @param mixed $resourceUri
 */
private function extractResourceDisplayName($rdfData, $resourceUri) {
    if (isset($rdfData[$resourceUri]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$resourceUri]['http://purl.org/dc/terms/identifier'] as $idObj) {
            if ($idObj['type'] === 'literal') {
                return $idObj['value'];
            }
        }
    }
    
    if (isset($rdfData[$resourceUri]['http://purl.org/dc/terms/title'])) {
        foreach ($rdfData[$resourceUri]['http://purl.org/dc/terms/title'] as $titleObj) {
            if ($titleObj['type'] === 'literal') {
                return $titleObj['value'];
            }
        }
    }
    
    return basename($resourceUri);
}

/**
 * This method extracts the display value for a context resource.
 * @param mixed $rdfData
 * @param mixed $subject
 * @param mixed $itemData
 * @param mixed $currentItemSetId
 * @return void
 */
private function extractArchaeologicalContext($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    $contextProperties = [
        'location' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInLocation',
                'excav:foundInLocation',
                "http://localhost/megalod/$currentItemSetId/excavation/foundInLocation"
            ],
            'label' => 'Found in Location',
            'propertyId' => 7680,
            'type' => 'resource'
        ],
        'square' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInSquare',
                'excav:foundInSquare',
                "http://localhost/megalod/$currentItemSetId/excavation/foundInSquare"
            ],
            'label' => 'Found in Square',
            'propertyId' => 7683,
            'type' => 'resource'
        ],
        'context' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInContext',
                'excav:foundInContext',
                "http://localhost/megalod/$currentItemSetId/excavation/foundInContext"
            ],
            'label' => 'Found in Context',
            'propertyId' => 7672,
            'type' => 'resource'
        ],
        'foundInSVU' => [
            'uris' => [
                'https://purl.org/megalod/ms/excavation/foundInSVU', 
                'excav:foundInSVU',
                "http://localhost/megalod/$currentItemSetId/excavation/foundInSVU"
            ],
            'label' => 'Found in SVU',
            'propertyId' => 7671
        ],
    ];
    
    foreach ($contextProperties as $propName => $config) {
        foreach ($config['uris'] as $uri) {
            if (isset($rdfData[$subject][$uri])) {
   
                
                if (!isset($itemData[$config['label']])) {
                    $itemData[$config['label']] = [];
                }
                
                foreach ($rdfData[$subject][$uri] as $contextObj) {
                    if ($contextObj['type'] === 'uri') {
                        $contextValue = $this->extractContextDisplayValue($rdfData, $contextObj['value']);
                        $displayValue = $contextValue ?: $contextObj['value'];
                        
                        
                        $itemData[$config['label']][] = [
                            'type' => 'uri',  
                            'property_id' => $config['propertyId'],
                            '@id' => $contextObj['value'],
                            'o:label' => $displayValue
                        ];
                        
   
                    }
                }
                break; 
            } else {
   
            }
        }
    }
}

/**
 * This method extracts encounter event data for an arrowhead item.
 * It looks for encounter events related to the arrowhead and processes them.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract encounter event data from
 * @param array &$itemData The item data array to populate with encounter properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractEncounterEventData($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    $encounterUris = [
        'https://cidoc-crm.org/extensions/crmsci/O19i_was_object_encountered_through',
        'crmsci:O19i_was_object_encountered_through'
    ];
    
    if ($currentItemSetId) {
        $encounterUris[] = "http://localhost/megalod/$currentItemSetId/crmsci/O19i_was_object_encountered_through";
    }
    
    $encounterEventUri = null;
    
    foreach ($encounterUris as $uri) {
        if (isset($rdfData[$subject][$uri])) {
            foreach ($rdfData[$subject][$uri] as $encounterObj) {
                if ($encounterObj['type'] === 'uri') {
                    $encounterEventUri = $encounterObj['value'];
   
                    break 2;
                }
            }
        }
    }
    
    if (!$encounterEventUri) {
   
        
        foreach ($encounterUris as $uri) {
            if (isset($rdfData[$subject][$uri])) {
                foreach ($rdfData[$subject][$uri] as $encounterObj) {
                    if ($encounterObj['type'] === 'uri') {
                        $encounterEventUri = $encounterObj['value'];
                        
                        if (!isset($itemData['Encounter Event'])) {
                            $itemData['Encounter Event'] = [];
                        }
                        
                        $itemData['Encounter Event'][] = [
                            'type' => 'uri',
                            'property_id' => 7686,
                            '@id' => $encounterEventUri,
                            'o:label' => 'Archaeological Encounter Event'
                        ];
                        
                        if (isset($rdfData[$encounterEventUri])) {
                            $this->processEncounterEvent($rdfData, $encounterEventUri, $itemData, $currentItemSetId);
                        }
                    }
                }
            }
        }
    }
    
    // Process the encounter event if found
    if ($encounterEventUri && isset($rdfData[$encounterEventUri])) {
        $this->processEncounterEvent($rdfData, $encounterEventUri, $itemData, $this->getCurrentItemSetContext());
    } else {
   
    }
}


/**
 * Extracts the display value for a context resource.
 * This method tries multiple strategies to find a meaningful display value for the context.
 * @param mixed $rdfData The RDF data array
 * @param mixed $contextUri The URI of the context resource
 * @return string|null The display value or null if not found
 */
private function extractContextDisplayValue($rdfData, $contextUri) {
   
    
    if (isset($rdfData[$contextUri])) {
        $resource = $rdfData[$contextUri];
        
        if (isset($resource['http://purl.org/dc/terms/identifier'])) {
            foreach ($resource['http://purl.org/dc/terms/identifier'] as $idObj) {
                if ($idObj['type'] === 'literal') {
   
                    return $idObj['value'];
                }
            }
        }
        
        if (isset($resource['http://dbpedia.org/ontology/informationName'])) {
            foreach ($resource['http://dbpedia.org/ontology/informationName'] as $nameObj) {
                if ($nameObj['type'] === 'literal') {
   
                    return $nameObj['value'];
                }
            }
        }
        
        if (isset($resource['http://www.w3.org/2000/01/rdf-schema#label'])) {
            foreach ($resource['http://www.w3.org/2000/01/rdf-schema#label'] as $labelObj) {
                if ($labelObj['type'] === 'literal') {
   
                    return $labelObj['value'];
                }
            }
        }
    }
    
    $uriValue = $this->extractIdentifierFromUriStructure($contextUri);
    if ($uriValue) {
   
        return $uriValue;
    }
    
    if (strpos($contextUri, '/location/') !== false) {
        return 'Excavation Location';
    }
    
    if (preg_match('/\/(\d+)$/', $contextUri, $matches)) {
   
        return $matches[1]; 
    }
    
   
    return null;
}

/**
 * Extracts coordinate data from the RDF data for a given subject.
 * This method enhances the extraction by looking for multiple coordinate properties.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract coordinate data from
 * @param array &$itemData The item data array to populate with coordinate properties
 * @param int|null $currentItemSetId The ID of the current item set, if available
 */
private function extractCoordinateDataEnhanced($rdfData, $subject, &$itemData, $currentItemSetId) {
   
    
    $this->extractCoordinateData($rdfData, $subject, $itemData, $currentItemSetId);

}



/**
 * Extracts media resources from the RDF data for a given subject.
 * This method looks for web resources and populates the item data with them.
 * @param mixed $rdfData The RDF data array
 * @param mixed $subject The subject URI to extract media resources from
 * @param array &$itemData The item data array to populate with media resources
 */
private function extractMediaResources($rdfData, $subject, &$itemData) {
   
    
    $mediaUris = [
        'http://www.europeana.eu/schemas/edm/Webresource',
        'edm:Webresource'
    ];
    
    foreach ($mediaUris as $uri) {
        if (isset($rdfData[$subject][$uri])) {
            if (!isset($itemData['Web Resources'])) {
                $itemData['Web Resources'] = [];
            }
            
            foreach ($rdfData[$subject][$uri] as $mediaObj) {
                if ($mediaObj['type'] === 'uri') {
                    $itemData['Web Resources'][] = [
                        'type' => 'uri',
                        'property_id' => 38, 
                        '@id' => $mediaObj['value'],
                        'o:label' => basename($mediaObj['value'])
                    ];
                    
   
                }
            }
            break;
        }
    }
}


/**
 * Retrieves the current item set context.
 * This method should return the ID of the current item set being processed.
 * @return int|null The current item set ID or null if not set
 */
private function getCurrentItemSetContext() {
   
    return $this->currentProcessingItemSetId ?? null;
}

/**
 * Extracts the context information for an arrowhead from the RDF data.
 * This method looks for excavation, location, square, context, SVU, date, and item identifier.
 * @param mixed $ttlData The RDF data in Turtle format
 * @return array The extracted context information
 */
private function extractArrowheadContextFromTtl($ttlData) {
    $context = [
        'excavation' => null,
        'location' => null,
        'square' => null,
        'context' => null,
        'svu' => null,
        'date' => null,
        'item_identifier' => null
    ];
    
   
   
    
    // Extract item identifier
    if (preg_match('/dct:identifier\s+"([^"]+)"/i', $ttlData, $matches)) {
        $context['item_identifier'] = $matches[1];
   
    }

    // Extract date
    if (preg_match('/dct:date\s+"([^"]+)"/i', $ttlData, $matches)) {
        $context['date'] = $matches[1];
   
    }
    
    // IMPROVED: Extract context reference with better pattern matching
    if (preg_match('/excav:foundInContext\s+<([^>]+)>/i', $ttlData, $matches)) {
        $contextUri = $matches[1];
   
        
        // Extract the context identifier from declaration
        if (preg_match('/<' . preg_quote($contextUri, '/') . '>\s+a\s+excav:Context\s*;\s*dct:identifier\s+"([^"]+)"(?:\^\^xsd:literal)?/i', $ttlData, $idMatches)) {
            $context['context'] = $idMatches[1];
   
        } else {
            // extract from URI structure 
            if (preg_match('/\/context\/([^\/\s>]+)/', $contextUri, $contextMatches)) {
                $context['context'] = $contextMatches[1];
   
            }
        }
    }
    
    if (!$context['context']) {
        if (preg_match('/<[^>]*\/context\/([^>\/\s]+)>\s+a\s+excav:Context/i', $ttlData, $matches)) {
            $context['context'] = $matches[1];
   
        }
    }
    
    if (preg_match('/excav:foundInSVU\s+<([^>]+)>/i', $ttlData, $matches)) {
        $svuUri = $matches[1];
   
        
        if (preg_match('/<' . preg_quote($svuUri, '/') . '>\s+a\s+excav:StratigraphicVolumeUnit\s*;\s*dct:identifier\s+"([^"]+)"(?:\^\^xsd:literal)?/i', $ttlData, $idMatches)) {
            $context['svu'] = $idMatches[1];
   
        } else {
            if (preg_match('/\/svu\/([^\/\s>]+)/', $svuUri, $svuMatches)) {
                $context['svu'] = $svuMatches[1];
   
            }
        }
    }
    
    if (!$context['svu']) {
        if (preg_match('/<[^>]*\/svu\/([^>\/\s]+)>\s+a\s+excav:StratigraphicVolumeUnit/i', $ttlData, $matches)) {
            $context['svu'] = $matches[1];
   
        }
    }


    
    if (preg_match('/excav:foundInLocation\s+<([^>]+)>/i', $ttlData, $matches)) {
        $locationUri = $matches[1];
   
        
        if (preg_match('/<' . preg_quote($locationUri, '/') . '>\s+a\s+excav:Location\s*;\s*dbo:informationName\s+"([^"]+)"/i', $ttlData, $nameMatches)) {
            $context['location'] = $this->createUrlSlug($nameMatches[1]);
   
        } else {
            if (preg_match('/\/location\/([^\/]+)$/', $locationUri, $locationMatches)) {
                $context['location'] = $locationMatches[1];
   
            }
        }
    }
    
    if (preg_match('/excav:foundInExcavation\s+<([^>]+)>/i', $ttlData, $matches)) {
        $excavationUri = $matches[1];
   
        
        if (preg_match('/<' . preg_quote($excavationUri, '/') . '>\s+a\s+excav:Excavation\s*;\s*dct:identifier\s+"([^"]+)"/i', $ttlData, $idMatches)) {
            $context['excavation'] = $idMatches[1];
   
        } else {
            if (preg_match('/\/excavation\/([^\/]+)(?:\/|$)/', $excavationUri, $excavationMatches)) {
                $context['excavation'] = $excavationMatches[1];
   
            }
        }
    }
    
    if (!$context['excavation'] && preg_match('/encounter\/encounter-(\d+)/', $ttlData, $matches)) {
        if (preg_match('/excav:EncounterEvent\s*;\s*.*?excav:foundInExcavation\s+<([^>]+)>/s', $ttlData, $encMatches)) {
            if (preg_match('/\/excavation\/([^\/]+)(?:\/|$)/', $encMatches[1], $excavationMatches)) {
                $context['excavation'] = $excavationMatches[1];
   
            }
        }
    }

    if (preg_match('/excav:foundInSquare\s+<([^>]+)>/i', $ttlData, $matches)) {
        $squareUri = $matches[1];
        if (preg_match('/\/square\/([^\/\s>]+)/', $squareUri, $squareMatches)) {
            $context['square'] = $squareMatches[1];
   
        }
    } 
    
    $hasValidContext = $context['excavation'] || $context['location'] || $context['context'] || $context['svu'] || $context['square'];
    
    
   
    
    return $context;
}

/**
 * Validates the context relationships for an arrowhead item.
 * This method checks if the context and SVU exist in the excavation and if their relationship is valid.
 * @param array $arrowheadContext The context data extracted from the arrowhead
 * @param int $itemSetId The ID of the item set to validate against
 * @return array An array containing validation results, errors, and details
 */
private function validateContextRelationships($arrowheadContext, $itemSetId) {
   
    
    $excavationRelationships = $this->getExcavationRelationshipsFromGraphDB($itemSetId);
   
    $errors = [];
    $details = [];

   
    
    if ($arrowheadContext['svu']) {
   
        if (strpos($arrowheadContext['svu'], '/') !== false) {
            $parts = explode('/', rtrim($arrowheadContext['svu'], '/'));
            $arrowheadContext['svu'] = end($parts);
        }
        
        if (!in_array($arrowheadContext['svu'], $excavationRelationships['svus'])) {
            $errors[] = "SVU '{$arrowheadContext['svu']}' does not exist in this excavation";
            $details['available_svus'] = $excavationRelationships['svus'];
        }
    }
    
    if ($arrowheadContext['context']) {
        if (strpos($arrowheadContext['context'], '/') !== false) {
            $parts = explode('/', rtrim($arrowheadContext['context'], '/'));
            $arrowheadContext['context'] = end($parts);
        }
        
        if (!in_array($arrowheadContext['context'], $excavationRelationships['contexts'])) {
            $errors[] = "Context '{$arrowheadContext['context']}' does not exist in this excavation";
            $details['available_contexts'] = $excavationRelationships['contexts'];
        }
    }
    
    if ($arrowheadContext['context'] && $arrowheadContext['svu']) {
        $relationshipExists = false;
        foreach ($excavationRelationships['context_svu_links'] as $link) {
            if ($link['context'] === $arrowheadContext['context'] && 
                $link['svu'] === $arrowheadContext['svu']) {
                $relationshipExists = true;
                break;
            }
        }
        
        if (!$relationshipExists) {
            $errors[] = "Invalid relationship: Context '{$arrowheadContext['context']}' is not linked to SVU '{$arrowheadContext['svu']}' in this excavation";
            $details['valid_relationships'] = $excavationRelationships['context_svu_links'];
        }
    }
    
   
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'details' => $details
    ];
}

/**
 * Retrieves excavation relationships from the GraphDB for a given item set ID.
 * This method queries the GraphDB to find contexts and SVUs related to the item set.
 * @param int $itemSetId The ID of the item set to query
 * @return array An array containing contexts, SVUs, and context-SVU links
 */
private function getExcavationRelationshipsFromGraphDB($itemSetId) {
    $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
    $query = "
    PREFIX excav: <https://purl.org/megalod/ms/excavation/>
    PREFIX dct: <http://purl.org/dc/terms/>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

    SELECT DISTINCT ?contextId ?svuId ?hasRelationship
    WHERE {
        GRAPH <$graphUri> {
            {
                ?context a excav:Context ;
                        dct:identifier ?contextId .
                OPTIONAL {
                    ?context excav:hasSVU ?svu .
                    ?svu dct:identifier ?svuId .
                    BIND(true AS ?hasRelationship)
                }
            }
            UNION
            {
                ?svu a excav:StratigraphicVolumeUnit ;
                     dct:identifier ?svuId .
            }
            UNION
            {
                # Also check public URIs format
                ?context excav:hasSVU ?publicSvu .
                BIND(REPLACE(str(?publicSvu), '.*/([^/]+)$', '$1') as ?svuId)
                BIND(true AS ?hasRelationship)
            }
        }
    }";

    try {
        $results = $this->executeGraphDbQuery($query);
        
        if (!empty($results) && isset($results['results']['bindings'])) {
            $contexts = [];
            $svus = [];
            $contextSvuLinks = [];
            
            foreach ($results['results']['bindings'] as $binding) {
                if (isset($binding['contextId'])) {
                    $contexts[] = $binding['contextId']['value'];
                }
                if (isset($binding['svuId'])) {
                    $svus[] = $binding['svuId']['value'];
                }
                if (isset($binding['hasRelationship']) && 
                    isset($binding['contextId']) && 
                    isset($binding['svuId'])) {
                    $contextSvuLinks[] = [
                        'context' => $binding['contextId']['value'],
                        'svu' => $binding['svuId']['value']
                    ];
                }
            }
            
            return [
                'contexts' => array_unique($contexts),
                'svus' => array_unique($svus),
                'context_svu_links' => $contextSvuLinks
            ];
        }
    } catch (\Exception $e) {
   
    }
    
    return [
        'contexts' => [],
        'svus' => [],
        'context_svu_links' => []
    ];
}

/**
 * Finds or creates an encounter event based on the arrowhead context.
 * This method checks if an encounter event already exists for the given context and item set.
 * If not, it creates a new encounter event.
 * @param array $arrowheadContext The context data extracted from the arrowhead
 * @param int $itemSetId The ID of the item set to create or find the encounter event in
 * @return array|null The encounter event data or null if not found or created
 */
private function findOrCreateEncounterEvent($arrowheadContext, $itemSetId) {
   
    
    $encounterSignature = $this->generateEncounterSignature($arrowheadContext);
    
    // Check if encounter event already exists
    $existingEncounter = $this->findExistingEncounterEvent($encounterSignature, $itemSetId);
    
    if ($existingEncounter) {
   
        return $existingEncounter;
    }
    
    // Create new encounter event
    $newEncounter = $this->createNewEncounterEvent($arrowheadContext, $itemSetId, $encounterSignature);
   
    
    return $newEncounter;
}

/**
 * Generates a unique signature for the encounter based on the context.
 * This method creates a hash signature that uniquely identifies the encounter event.
 * @param array $context The context data for the encounter
 * @return string The generated MD5 signature
 */
private function generateEncounterSignature($context) {
    $signature = [
        'excavation' => $context['excavation'] ?: 'unknown',
        'context' => $context['context'] ?: 'no-context',
        'svu' => $context['svu'] ?: 'no-svu',
        'date' => $context['date'],
        'square' => $context['square'] ?: 'no-square'
    ];
    
    return md5(json_encode($signature));
}

/**
 * Finds an existing encounter event by signature or title.
 * This method searches for an encounter event in the specified item set that matches the given signature.
 * If no match is found by signature, it tries to find by title.
 * @param string $signature The MD5 signature of the encounter
 * @param int $itemSetId The ID of the item set to search in
 * @return array|null The encounter event data or null if not found
 */
private function findExistingEncounterEvent($signature, $itemSetId) {
    try {
        $searchParams = [
            'resource_class_id' => 123,
            'item_set_id' => $itemSetId,
            'property' => [
                [
                    'property' => 10,
                    'type' => 'eq',
                    'text' => $signature
                ]
            ]
        ];
        
        $response = $this->api()->search('items', $searchParams);
        $encounters = $response->getContent();
        
        if (!empty($encounters)) {
            $encounter = $encounters[0];
   
            return [
                'id' => $encounter->id(),
                'signature' => $signature,
                'omeka_id' => $encounter->id()
            ];
        }
        
        $title = $this->generateEncounterTitle($this->contextFromSignature($signature));
        
        $titleSearchParams = [
            'resource_class_id' => 123,
            'item_set_id' => $itemSetId,
            'property' => [
                [
                    'property' => 1, 
                    'type' => 'eq',
                    'text' => $title
                ]
            ]
        ];
        
        $response = $this->api()->search('items', $titleSearchParams);
        $encounters = $response->getContent();
        
        if (!empty($encounters)) {
            $encounter = $encounters[0];
   
            return [
                'id' => $encounter->id(),
                'signature' => $signature, 
                'omeka_id' => $encounter->id()
            ];
        }
    } catch (\Exception $e) {
   
    }
    
    return null;
}
/**
 * This method attempts to reverse engineer the context from a signature.
 * @param mixed $signature
 * @return array|array{context: string, date: string, excavation: string, square: string, svu: string}
 */
private function contextFromSignature($signature) {
    static $signatureCache = [];
    
    // Return from cache if already processed
    if (isset($signatureCache[$signature])) {
        return $signatureCache[$signature];
    }
    
   
    $context = [
        'excavation' => 'unknown',
        'context' => 'unknown',
        'svu' => 'unknown',
        'date' => date('Y-m-d'),
        'square' => 'unknown'
    ];
    
    try {
        $searchParams = [
            'property' => [
                [
                    'property' => 10, 
                    'type' => 'eq',
                    'text' => $signature
                ]
            ]
        ];
        
        $response = $this->api()->search('items', $searchParams);
        $encounters = $response->getContent();
        
        if (!empty($encounters)) {
            $encounter = $encounters[0];
            
            $values = $encounter->values();
            
          
            if (isset($values['excav:foundInExcavation'])) {
                $propertyValues = $values['excav:foundInExcavation'];
                if (is_array($propertyValues)) {
                    foreach ($propertyValues as $valueRepresentation) {
                        if (method_exists($valueRepresentation, 'uri') && $valueRepresentation->uri()) {
                            $context['excavation'] = $valueRepresentation->uri();
                        } elseif (method_exists($valueRepresentation, 'value')) {
                            $context['excavation'] = $valueRepresentation->value();
                        }
                        break;
                    }
                }
            }
            
            if (isset($values['excav:foundInContext'])) {
                $propertyValues = $values['excav:foundInContext'];
                if (is_array($propertyValues)) {
                    foreach ($propertyValues as $valueRepresentation) {
                        if (method_exists($valueRepresentation, 'uri') && $valueRepresentation->uri()) {
                            $context['context'] = $valueRepresentation->uri();
                        } elseif (method_exists($valueRepresentation, 'value')) {
                            $context['context'] = $valueRepresentation->value();
                        }
                        break;
                    }
                }
            }
            
            if (isset($values['excav:foundInSVU'])) {
                $propertyValues = $values['excav:foundInSVU'];
                if (is_array($propertyValues)) {
                    foreach ($propertyValues as $valueRepresentation) {
                        if (method_exists($valueRepresentation, 'uri') && $valueRepresentation->uri()) {
                            $context['svu'] = $valueRepresentation->uri();
                        } elseif (method_exists($valueRepresentation, 'value')) {
                            $context['svu'] = $valueRepresentation->value();
                        }
                        break;
                    }
                }
            }
            
            // Get date
            if (isset($values['dcterms:date'])) {
   
                $propertyValues = $values['dcterms:date'];
   
                
                if (is_array($propertyValues)) {
                    foreach ($propertyValues as $valueRepresentation) {
   
                        if (method_exists($valueRepresentation, 'value')) {
                            $context['date'] = $valueRepresentation->value();
   
                        }
                        break;
                    }
                } else {
                    
                    if (method_exists($propertyValues, 'values')) {
                        $actualValues = $propertyValues->values();
                        foreach ($actualValues as $valueRepresentation) {
                            if (method_exists($valueRepresentation, 'value')) {
                                $context['date'] = $valueRepresentation->value();
                                break;
                            }
                        }
                    }
                }
            }
            
            // Get square
            if (isset($values['excav:foundInSquare'])) {
                $propertyValues = $values['excav:foundInSquare'];
                if (is_array($propertyValues)) {
                    foreach ($propertyValues as $valueRepresentation) {
                        if (method_exists($valueRepresentation, 'uri') && $valueRepresentation->uri()) {
                            $context['square'] = $valueRepresentation->uri();
                        } elseif (method_exists($valueRepresentation, 'value')) {
                            $context['square'] = $valueRepresentation->value();
                        }
                        break;
                    }
                }
            }
        }
    } catch (\Exception $e) {
   
    }
    
    $signatureCache[$signature] = $context;
    
    return $context;
}


/**
 * Creates a new encounter event in the specified item set.
 * This method constructs the encounter data and sends it to the API to create a new item.
 * @param array $context The context data for the encounter
 * @param int $itemSetId The ID of the item set to create the encounter in
 * @param string $signature The MD5 signature of the encounter
 * @return array The created encounter event data
 */
private function createNewEncounterEvent($context, $itemSetId, $signature) {
   
   
    $encounterData = [
        'o:resource_class' => ['o:id' => 123],
        'o:item_set' => [['o:id' => $itemSetId]],
        'dcterms:title' => [
            [
                'type' => 'literal',
                'property_id' => 1,
                '@value' => $this->generateEncounterTitle($context)
            ]
        ],
        'dcterms:description' => [
            [
                'type' => 'literal',
                'property_id' => 4,
                '@value' => $this->generateEncounterDescription($context)
            ]
        ],
        'dcterms:date' => [
            [
                'type' => 'literal',
                'property_id' => 7,
                '@value' => $context['date']
            ]
        ],
        // Store signature for future lookups
        'dcterms:identifier' => [
            [
                'type' => 'literal',
                'property_id' => 10,
                '@value' => $signature
            ]
        ]
    ];
    
    // Add context references
    if ($context['context']) {
        $encounterData['excav:foundInContext'] = [
            [
                'type' => 'literal',
                'property_id' => 7672,
                '@value' => $context['context']
            ]
        ];
    }
    
    if ($context['svu']) {
        $encounterData['excav:foundInSVU'] = [
            [
                'type' => 'literal',
                'property_id' => 7671,
                '@value' => $context['svu']
            ]
        ];
    }
    
    try {
        $response = $this->api()->create('items', $encounterData);
        $encounter = $response->getContent();
        
        return [
            'id' => $encounter->id(),
            'signature' => $signature,
            'omeka_id' => $encounter->id()
        ];
    } catch (\Exception $e) {
   
        throw $e;
    }
}

/**
 * Generates a title for the encounter event based on the context.
 * @param mixed $ttlData
 * @param mixed $encounterEvent
 * @param mixed $itemSetId
 * @return string
 */
private function addEncounterEventToTtl($ttlData, $encounterEvent, $itemSetId) {
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
   
    $encounterUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/encounter/encounter-{$encounterEvent['omeka_id']}";
    
    // Extract item identifier and context from TTL
    $itemIdentifier = $this->extractItemIdentifierFromTtl($ttlData);
   
    $arrowheadContext = $this->extractArrowheadContextFromTtl($ttlData);
    
    $encounterTriple = "    crmsci:O19i_was_object_encountered_through <$encounterUri> ;\n";
    
    $pattern = '/(dct:identifier\s+"[^"]+"\^\^xsd:literal\s*;)(\s*)/';
    $replacement = "$1\n$encounterTriple$2";
    
    $enhancedTtl = preg_replace($pattern, $replacement, $ttlData, 1);
    
    $selfRefPattern = '/excav:foundInExcavation\s+<http:\/\/localhost\/megalod\/' . $itemSetId . '\/item\/' . preg_quote($itemIdentifier, '/') . '>\s*;\s*\n/';
    $enhancedTtl = preg_replace($selfRefPattern, '', $enhancedTtl);
    
    $excavationRefPattern = '/excav:foundInExcavation\s+<http:\/\/localhost\/megalod\/' . $itemSetId . '\/excavation\/[^>]+>\s*;\s*\n/';
    $enhancedTtl = preg_replace($excavationRefPattern, '', $enhancedTtl);
    
    $encounterDefinition = "\n\n# =========== ENCOUNTER EVENT ===========\n\n";
    $encounterDefinition .= "<$encounterUri> a excav:EncounterEvent ;\n";

    // Add date if available
    if (!empty($arrowheadContext['date'])) {
        $encounterDefinition .= "    dct:date \"" . $arrowheadContext['date'] . "\"^^xsd:literal ;\n";
    }

    // Add encountered object reference
    $itemUri = "http://localhost/megalod/$itemSetId/item/$itemIdentifier";
    $encounterDefinition .= "    crmsci:O19_encountered_object <$itemUri> ;\n";

    // Add excavation reference
    $excavationUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier";
    $encounterDefinition .= "    excav:foundInExcavation <$excavationUri> ;\n";

    // Add location reference
    $locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
    if ($locationUri && $this->locationHasData($itemSetId)) {
        $encounterDefinition .= "    excav:foundInLocation <$locationUri> ;\n";
    }


    if ($arrowheadContext['context']) {
        $contextUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/context/{$arrowheadContext['context']}";
        $encounterDefinition .= "    excav:foundInContext <$contextUri> ;\n";
    }

    if ($arrowheadContext['svu']) {
        $svuUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/svu/{$arrowheadContext['svu']}";
        $encounterDefinition .= "    excav:foundInSVU <$svuUri> ;\n";
    }

    $encounterDefinition = rtrim($encounterDefinition, " ;\n") . " .\n\n";
    /*
    $encounterDefinition .= "\n# =========== CONTEXT ENTITY DECLARATIONS ===========\n\n";
    
    $existingDeclarations = $this->checkExistingDeclarations($enhancedTtl, $itemSetId, $excavationIdentifier);
    
    if (!$existingDeclarations['excavation']) {
        $encounterDefinition .= "<$excavationUri> a excav:Excavation ;\n";
        $encounterDefinition .= "    dct:identifier \"$excavationIdentifier\"^^xsd:literal .\n\n";
    }
    
    if ($arrowheadContext['context'] && !$existingDeclarations['context']) {
        $contextUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/context/{$arrowheadContext['context']}";
        $encounterDefinition .= "<$contextUri> a excav:Context ;\n";
        $encounterDefinition .= "    dct:identifier \"{$arrowheadContext['context']}\"^^xsd:literal .\n\n";
    }
    
    if ($arrowheadContext['svu'] && !$existingDeclarations['svu']) {
        $svuUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/svu/{$arrowheadContext['svu']}";
        $encounterDefinition .= "<$svuUri> a excav:StratigraphicVolumeUnit ;\n";
        $encounterDefinition .= "    dct:identifier \"{$arrowheadContext['svu']}\"^^xsd:literal .\n\n";
    }
    
    if ($arrowheadContext['location'] && !$existingDeclarations['location']) {
        $locationUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/location/{$arrowheadContext['location']}";
        $encounterDefinition .= "<$locationUri> a excav:Location ;\n";
        $encounterDefinition .= "    dct:identifier \"{$arrowheadContext['location']}\"^^xsd:literal .\n\n";
    }
    
    if ($arrowheadContext['square'] && !$existingDeclarations['square']) {
        $squareUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/{$arrowheadContext['square']}";
        $encounterDefinition .= "<$squareUri> a excav:Square ;\n";
        $encounterDefinition .= "    dct:identifier \"{$arrowheadContext['square']}\"^^xsd:literal .\n\n";
    }
    
    return $enhancedTtl . $encounterDefinition;*/

    return $enhancedTtl . $encounterDefinition;
}
/**
 * Checks if the location has sufficient data to be considered valid.
 * This method checks if the location data contains any meaningful information.
 * @param int $itemSetId The ID of the item set to check
 * @return bool True if the location has sufficient data, false otherwise
 */
private function locationHasData($itemSetId) {
    $locationData = $this->getLocationDataFromExcavation($itemSetId);
    return !empty($locationData) && (
        !empty($locationData['name']) ||
        !empty($locationData['district']) ||
        !empty($locationData['parish']) ||
        !empty($locationData['country']) ||
        !empty($locationData['lat']) ||
        !empty($locationData['long'])
    );
}
/**
 * Checks for existing declarations in the TTL data.
 * This method looks for existing context, SVU, square, location, and excavation declarations
 * to avoid duplicates when adding new encounter events.
 * @param string $ttlData The TTL data to check
 * @param int $itemSetId The ID of the item set to check against
 * @param string $excavationIdentifier The excavation identifier to look for
 * @return array An associative array indicating which declarations already exist
 */
private function checkExistingDeclarations($ttlData, $itemSetId, $excavationIdentifier) {
    $existing = [
        'context' => false,
        'svu' => false,
        'square' => false,
        'location' => false,
        'excavation' => false
    ];
    
    if (preg_match("/<http:\/\/localhost\/megalod\/$itemSetId\/excavation\/$excavationIdentifier>\s+a\s+excav:Excavation/", $ttlData) ||
        preg_match("/<https:\/\/purl\.org\/megalod\/$itemSetId\/excavation\/$excavationIdentifier>\s+a\s+excav:Excavation/", $ttlData)) {
        $existing['excavation'] = true;
    }
    
    if (preg_match("/<http:\/\/localhost\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/context\/[^>]+>\s+a\s+excav:Context/", $ttlData) ||
        preg_match("/<https:\/\/purl\.org\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/context\/[^>]+>\s+a\s+excav:Context/", $ttlData)) {
        $existing['context'] = true;
    }
    
    // Check for existing SVU declarations - handle multiple URI formats
    if (preg_match("/<http:\/\/localhost\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/svu\/[^>]+>\s+a\s+excav:StratigraphicVolumeUnit/", $ttlData) ||
        preg_match("/<https:\/\/purl\.org\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/svu\/[^>]+>\s+a\s+excav:StratigraphicVolumeUnit/", $ttlData)) {
        $existing['svu'] = true;
    }
    
    if (preg_match("/<http:\/\/localhost\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/square\/[^>]+>\s+a\s+excav:Square/", $ttlData) ||
        preg_match("/<https:\/\/purl\.org\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/square\/[^>]+>\s+a\s+excav:Square/", $ttlData)) {
        $existing['square'] = true;
    }
    
    if (preg_match("/<http:\/\/localhost\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/location\/[^>]+>\s+a\s+excav:Location/", $ttlData) ||
        preg_match("/<https:\/\/purl\.org\/megalod\/$itemSetId\/excavation\/$excavationIdentifier\/location\/[^>]+>\s+a\s+excav:Location/", $ttlData)) {
        $existing['location'] = true;
    }
    
    return $existing;
}

/**
 * Generates a title for the encounter event based on the context.
 * This method constructs a human-readable title that includes the date, context, and SVU.
 * @param array $context The context data for the encounter
 * @return string The generated title
 */
private function generateEncounterTitle($context) {
    $parts = [];
    $parts[] = "Archaeological Encounter Event ";
    if ($context['date']) {
        $parts[] = $context['date'];
    }
    
    if ($context['context'] && $context['svu']) {
        $parts[] = "Context {$context['context']}, SVU {$context['svu']}";
    } elseif ($context['context']) {
        $parts[] = "Context {$context['context']}";
    } elseif ($context['svu']) {
        $parts[] = "SVU {$context['svu']}";
    }
    
    return implode(' - ', $parts) ?: 'Archaeological Encounter Event';
}

/**
 * Generates a description for the encounter event based on the context.
 * This method constructs a detailed description that includes the date, context, SVU, and square.
 * @param array $context The context data for the encounter
 * @return string The generated description
 */
private function generateEncounterDescription($context) {
    $description = "Archaeological encounter event documenting finds";
    
    if ($context['date']) {
        $description .= " from " . $context['date'];
    }
    
    $contextParts = [];
    if ($context['context']) $contextParts[] = "context {$context['context']}";
    if ($context['svu']) $contextParts[] = "stratigraphic unit {$context['svu']}";
    if ($context['square']) $contextParts[] = "square {$context['square']}";
    
    if (!empty($contextParts)) {
        $description .= " in " . implode(', ', $contextParts);
    }
    
    return $description . ".";
}


/**
 * Extracts the item identifier from the TTL data.
 * This method looks for the dct:identifier property in the TTL data and returns its value.
 * @param string $ttlData The TTL data to extract the identifier from
 * @return string The extracted item identifier or 'unknown-item' if not found
 */
private function extractItemIdentifierFromTtl($ttlData) {
    if (preg_match('/dct:identifier\s+"([^"]+)"/i', $ttlData, $matches)) {
        return $matches[1];
    }
    return 'unknown-item';
}



/**
 * This method extracts the idntifier from a URI.
 * @param mixed $uri
 * @return string
 */
private function extractIdentifierFromUri($uri) {
    $parts = explode('/', $uri);
    return end($parts);
}


/**
 * Extracts SVU data from RDF data.
 * This method retrieves the name and description of the SVU from the RDF data.
 * @param array $rdfData The RDF data containing SVU information
 * @param string $svuUri The URI of the SVU to extract data from
 * @return array|null An associative array with 'name' and 'description', or null if not found
 */
private function extractSvuData($rdfData, $svuUri) {
    $data = [
        'name' => null,
        'description' => null
    ];
    
    // Extract identifier as name
    if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/identifier'])) {
        foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/identifier'] as $idObj) {
            if ($idObj['type'] === 'literal') {
                $data['name'] = $idObj['value'];
                break;
            }
        }
    }
    
    // Extract description
    if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/description'])) {
        foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/description'] as $descObj) {
            if ($descObj['type'] === 'literal') {
                $data['description'] = $descObj['value'];
                break;
            }
        }
    }
    
    return ($data['name'] || $data['description']) ? $data : null;
}



/**
 * Extracts the measurement value from RDF data.
 * This method retrieves the value of a typometry measurement from the RDF data.
 * @param array $rdfData The RDF data containing typometry information
 * @param string $typometryUri The URI of the typometry measurement to extract
 * @return string|null The extracted measurement value or null if not found
 */
private function extractMeasurementValue($rdfData, $typometryUri) {
   
    
    if (!isset($rdfData[$typometryUri])) {
   
        return null;
    }
    
   
    
    if (isset($rdfData[$typometryUri]['http://schema.org/value'])) {
        foreach ($rdfData[$typometryUri]['http://schema.org/value'] as $valueObj) {
            if ($valueObj['type'] === 'literal') {
   
                return $valueObj['value'];
            }
        }
    }
    
    $alternativeValueProps = [
        'http://www.w3.org/1999/02/22-rdf-syntax-ns#value',
        'http://purl.org/dc/terms/extent',
        'http://qudt.org/schema/qudt#numericValue'
    ];
    
    foreach ($alternativeValueProps as $valueProp) {
        if (isset($rdfData[$typometryUri][$valueProp])) {
            foreach ($rdfData[$typometryUri][$valueProp] as $valueObj) {
                if ($valueObj['type'] === 'literal') {
   
                    return $valueObj['value'];
                }
            }
        }
    }
    
   
    return null;
}


/**
 * Extracts the measurement unit from RDF data.
 * This method retrieves the unit of a typometry measurement from the RDF data.
 * @param array $rdfData The RDF data containing typometry information
 * @param string $typometryUri The URI of the typometry measurement to extract
 * @return string|null The extracted measurement unit or null if not found
 */
private function extractMeasurementUnit($rdfData, $typometryUri) {
   
    
    if (!isset($rdfData[$typometryUri])) {
        return null;
    }
    
    if (isset($rdfData[$typometryUri]['http://schema.org/UnitCode'])) {
        foreach ($rdfData[$typometryUri]['http://schema.org/UnitCode'] as $unitObj) {
            if ($unitObj['type'] === 'literal') {
   
                return $unitObj['value'];
            } elseif ($unitObj['type'] === 'uri') {
                $parts = explode('/', $unitObj['value']);
                $unit = end($parts);
   
                return $unit;
            }
        }
    }
    
    $alternativeUnitProps = [
        'http://schema.org/unitCode',
        'http://purl.org/dc/terms/format',
        'http://qudt.org/schema/qudt#unit',
        'http://qudt.org/schema/qudt#hasUnit'
    ];
    
    foreach ($alternativeUnitProps as $unitProp) {
        if (isset($rdfData[$typometryUri][$unitProp])) {
            foreach ($rdfData[$typometryUri][$unitProp] as $unitObj) {
                if ($unitObj['type'] === 'literal') {
   
                    return $unitObj['value'];
                } elseif ($unitObj['type'] === 'uri') {
                    $parts = explode('/', $unitObj['value']);
                    $unit = end($parts);
   
                    return $unit;
                }
            }
        }
    }
    
    if (isset($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
        foreach ($rdfData[$typometryUri]['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
            if ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Weight') {
                $value = $this->extractMeasurementValue($rdfData, $typometryUri);
                if ($value !== null) {
                    if (preg_match('/\s([a-zA-Z]+)/', $value, $matches)) {
                        $unit = $matches[1];
   
                        return $unit;
                    } else {
   
                        return null; 
                    }
                }
            }
        }
    }
    
   
    return null;
}


/**
 * Extracts a meaningful identifier from RDF data for a given resource URI.
 * This method attempts to find an identifier in the RDF data, falling back to URI structure if necessary.
 * @param array $rdfData The RDF data containing resource information
 * @param string $resourceUri The URI of the resource to extract the identifier from
 * @return string|null The extracted identifier or null if not found
 */
private function extractResourceIdentifier($rdfData, $resourceUri) {
   
   
    
    if (!isset($rdfData[$resourceUri])) {
   
        
        $identifier = $this->extractIdentifierFromUriStructure($resourceUri);
        if ($identifier) {
   
            return $identifier;
        }
        
        return null;
    }
    
    $identifierPredicates = [
        'http://purl.org/dc/terms/identifier',
        'dct:identifier',
        'dcterms:identifier'
    ];
    
    foreach ($identifierPredicates as $predicate) {
        if (isset($rdfData[$resourceUri][$predicate])) {
            foreach ($rdfData[$resourceUri][$predicate] as $idObj) {
                if ($idObj['type'] === 'literal') {
   
                    return $idObj['value'];
                }
            }
        }
    }
    
    $identifier = $this->extractIdentifierFromUriStructure($resourceUri);
    if ($identifier) {
   
        return $identifier;
    }
    
   
    return null;
}
/**
 * Retrieves the  location URI from an excavation item set.
 * This method checks if the excavation has a valid location and returns its URI.
 * If no valid location is found, it constructs a fallback URI based on the excavation identifier.
 * @param int $itemSetId The ID of the item set to check
 * @return string|null The real location URI or null if not found
 */
private function getRealLocationUriFromExcavation($itemSetId) {
   
    
    if (!$itemSetId) {
        return null;
    }
    
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
    if (!$excavationIdentifier) {
        return null;
    }

    $locationData = $this->getLocationDataFromExcavation($itemSetId);
    if (empty($locationData) || (
        empty($locationData['name']) &&
        empty($locationData['district']) &&
        empty($locationData['parish']) &&
        empty($locationData['country']) &&
        empty($locationData['lat']) &&
        empty($locationData['long'])
    )) {
   
        return null;
    }
    
    $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
    $locationQuery = "
        PREFIX excav: <https://purl.org/megalod/ms/excavation/>
        PREFIX dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#>
        
        SELECT ?locationUri
        WHERE {
            GRAPH <$graphUri> {
                ?excavation a excav:Excavation ;
                           dul:hasLocation ?locationUri .
            }
        }
        LIMIT 1
    ";
    
    try {
        $results = $this->querySparql($locationQuery);
        
        if (!empty($results) && isset($results[0]['locationUri'])) {
            $locationUri = $results[0]['locationUri']['value'];
   
            return $locationUri;
        }
    } catch (\Exception $e) {
   
    }
    
    $locationUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/location/excavation-location";
   
    return $locationUri;
}
/**
 * Extracts a meaningful identifier from a resource URI structure.
 * This method handles various URI patterns to extract identifiers for contexts, SVUs, and squares.
 * @param string $resourceUri The resource URI to extract the identifier from
 * @return string|null The extracted identifier or null if not found
 */
private function extractIdentifierFromUriStructure($resourceUri) {
   
    
    if (preg_match('/\/svu\/([^\/]+)$/', $resourceUri, $matches)) {
   
        return $matches[1];
    }
    
    // Pattern for context URIs: extract the last segment after /context/
    if (preg_match('/\/context\/([^\/]+)$/', $resourceUri, $matches)) {
   
        return $matches[1];
    }
    
    if (preg_match('/\/square\/([^\/]+)$/', $resourceUri, $matches)) {
   
        return $matches[1];
    }
    
   
    if (preg_match('/\/([^\/]+)\/item-(\d+)$/', $resourceUri, $matches)) {
        $resourceType = $matches[1]; 
        $itemId = $matches[2];       
        
   
        
        $realIdentifier = $this->getRealIdentifierFromOmekaItem($itemId);
        if ($realIdentifier) {
   
            return $realIdentifier;
        }
        
        switch (strtolower($resourceType)) {
            case 'context':
                return "CTX-" . str_pad($itemId % 1000, 3, '0', STR_PAD_LEFT);
            case 'svu':
                return "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT); 
            case 'square':
                $letterIndex = ($itemId - 1) % 26;
                $letter = chr(65 + $letterIndex); 
                $number = floor(($itemId - 1) / 26) + 1;
                return $letter . $number; 
            default:
                return $resourceType . "-" . ($itemId % 1000);
        }
    }
    

    if (preg_match('/\/([^\/]+)\/([^\/]+)$/', $resourceUri, $matches)) {
        $resourceType = $matches[1];
        $identifier = $matches[2];
        
        if (!preg_match('/^item-\d+$/', $identifier)) {
   
            return $identifier;
        }
    }
    
    $parts = explode('/', $resourceUri);
    $lastPart = end($parts);
    
    if (preg_match('/^[A-Za-z0-9-]+$/', $lastPart) && strlen($lastPart) > 1 && !preg_match('/^\d+$/', $lastPart)) {
   
        return $lastPart;
    }
    
    return null;
}


/**
 * this method retrieves the real identifier from an Omeka item.
 * @param mixed $itemId
 */
private function getRealIdentifierFromOmekaItem($itemId) {
    try {
   
        
        $item = $this->api()->read('items', $itemId)->getContent();
        
        $values = $item->values();

        if (isset($values['dcterms:identifier'])) {
   
            foreach ($values['dcterms:identifier'] as $value) {
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $identifier = $value->value();
   
                    return $identifier;
                }
            }
        }
        
        $title = $item->displayTitle();
   
        
        $patterns = [
            '/Stratigraphic Unit\s+([A-Za-z0-9\-_]+)/',
            
            '/Context\s+([A-Za-z0-9\-_]+)/',
            
            '/Square\s+([A-Za-z0-9\-_]+)/',
            
            '/Arrowhead\s+([A-Za-z0-9\-_]+)/',
            
            '/\b(Layer-\d+)\b/',          
            '/\b(CTX-\d+)\b/',            
            '/\b(CV-\d+-\d+)\b/',        
            '/\b(CV-\d+)\b/',             
            '/\b([A-Z]\d+)\b/',           
            '/\b(AH-[A-Z0-9]+)\b/',       
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
   
                return $matches[1];
            }
        }
        
        $resourceClass = $item->resourceClass();
        if ($resourceClass) {
            $className = $resourceClass->label();
   
            
            switch (strtolower($className)) {
                case 'context':
                    $identifier = "CTX-" . str_pad($itemId % 1000, 3, '0', STR_PAD_LEFT);
                    break;
                case 'stratigraphic unit':
                case 'svu':
                case 'stratigraphic volume unit':
                    $identifier = "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT);
                    break;
                case 'square':
                    $letters = ['A', 'B', 'C', 'D'];
                    $letter = $letters[($itemId - 1) % 4];
                    $number = (($itemId - 1) % 4) + 1;
                    $identifier = $letter . $number;
                    break;
                case 'arrowhead':
                    $identifier = "AH-" . str_pad($itemId % 1000, 3, '0', STR_PAD_LEFT);
                    break;
                default:
                    $identifier = "ITEM-$itemId";
                    break;
            }
            
   
            return $identifier;
        }
        
        $fallbackIdentifier = "ITEM-$itemId";
   
        return $fallbackIdentifier;
        
    } catch (\Exception $e) {
   
        return "ITEM-$itemId";
    }
}



/**
 * Finds an item by its identifier, with optional item set constraint.
 * This method searches for an item using various strategies based on the identifier format.
 * @param string $identifier The identifier to search for
 * @param int|null $itemSetId Optional item set ID to constrain the search
 * @return \Omeka\Api\Representation\ItemRepresentation|null The found item or null if not found
 */
private function findItemByIdentifier($identifier, $itemSetId = null) {
    try {
        
        $searchVariations = $this->generateIdentifierVariations($identifier);
   
        
        foreach ($searchVariations as $searchTerm) {
   
            
            $searchParams = [
                'property' => [
                    [
                        'property' => 10, 
                        'type' => 'eq',
                        'text' => $searchTerm
                    ]
                ],
                'limit' => 10
            ];
            
            if ($itemSetId) {
                $searchParams['item_set_id'] = $itemSetId;
            }
            
            $response = $this->api()->search('items', $searchParams);
            $items = $response->getContent();
            
            if (!empty($items)) {
                foreach ($items as $item) {
                    if ($itemSetId) {
                        $belongsToItemSet = false;
                        foreach ($item->itemSets() as $itemSet) {
                            if ($itemSet->id() == $itemSetId) {
                                $belongsToItemSet = true;
                                break;
                            }
                        }
                        
                        if ($belongsToItemSet) {
   
                            return $item;
                        }
                    } else {
   
                        return $items[0];
                    }
                }
            }
            
            $titleSearchParams = [
                'property' => [
                    [
                        'property' => 1, 
                        'type' => 'in',
                        'text' => $searchTerm
                    ]
                ],
                'limit' => 10
            ];
            
            if ($itemSetId) {
                $titleSearchParams['item_set_id'] = $itemSetId;
            }
            
            $response = $this->api()->search('items', $titleSearchParams);
            $items = $response->getContent();
            
            if (!empty($items)) {
                foreach ($items as $item) {
                    if ($itemSetId) {
                        foreach ($item->itemSets() as $itemSet) {
                            if ($itemSet->id() == $itemSetId) {
   
                                return $item;
                            }
                        }
                    } else {
   
                        return $items[0];
                    }
                }
            }
        }
        
   
        return null;
        
    } catch (\Exception $e) {
   
        return null;
    }
}


/**
 * Generates variations of an identifier for searching.
 * This method creates multiple variations of the identifier to improve search results.
 * It handles common prefixes, numeric identifiers, and square patterns.
 * @param string $identifier The original identifier to generate variations for
 * @return array An array of identifier variations
 */
private function generateIdentifierVariations($identifier) {
    $variations = [$identifier]; // Always include the original
    
    // Remove common prefixes and add as variations
    $prefixesToTry = ['Context-', 'SVU-', 'Square-', 'EXC-', 'CV-'];
    foreach ($prefixesToTry as $prefix) {
        if (strpos($identifier, $prefix) === 0) {
            $withoutPrefix = substr($identifier, strlen($prefix));
            $variations[] = $withoutPrefix;
            
            // For contexts, also try CV- prefix variations
            if ($prefix === 'Context-') {
                $variations[] = 'CV-' . $withoutPrefix;
            }
        }
    }
    
    // For numeric identifiers, try common prefixes
    if (preg_match('/^\d+$/', $identifier)) {
        $variations[] = 'CV-' . str_pad($identifier, 3, '0', STR_PAD_LEFT); 
        $variations[] = 'CV-001-' . $identifier; 
        
        // For square patterns
        if ($identifier <= 26) {
            $letter = chr(64 + ($identifier % 26 + 1)); 
            $number = ceil($identifier / 26);
            $variations[] = $letter . $number; 
        }
    }
    
    return array_unique($variations);
}


/* * Transforms the collecting form data into excavation data.
 * This method maps the form fields to the excavation data structure.
 * @param array $formData The form data to transform
 * @return array The transformed excavation data
 */
private function transformCollectingFormToExcavationData($formData)
{
   

    $excavationData = [];
    
    $fieldMappings = [
        'prompt_32' => 'excavation_id',        // Acronym (excavation identifier)
        'prompt_35' => 'site_name',            // Name of the Location 
        'prompt_34' => 'parish',               // parish of Excavation
        'prompt_97' => 'district',             // district of Excavation
        'prompt_51' => 'country',              // Country of Excavation
        'prompt_39' => 'latitude',             // GPS Latitude
        'prompt_40' => 'longitude',            // GPS Longitude
    ];
    
    // Process the basic form mappings
    foreach ($fieldMappings as $collectingField => $excavationField) {
        if (isset($formData[$collectingField]) && !empty($formData[$collectingField])) {
            $excavationData[$excavationField] = $formData[$collectingField];
        }
    }
    
    // Process archaeologist data
    $excavationData['archaeologist'] = $this->processArchaeologistDataFromForm($formData);
    
    // Process entities data
    if (isset($formData['entities_data']) && !empty($formData['entities_data'])) {
        $entitiesJson = $formData['entities_data'];
   
        
        $entitiesData = json_decode($entitiesJson, true);
        if ($entitiesData) {
            $excavationData['entities'] = $entitiesData;
        }
    }

    
   
    
    return $excavationData;
}
/**
 * Processes excavation data from RDF and populates item data.
 * This method extracts location, GPS coordinates, and other relevant information from RDF data.
 * @param array $rdfData The RDF data containing excavation information
 * @param string $subject The subject URI to process
 * @param array &$itemData The item data to populate with extracted information
 */

private function processExcavationData($rdfData, $subject, &$itemData) {
   
   
    
    $currentItemSetId = $this->getCurrentItemSetContext();


    
    
    // Extract location information
    if (isset($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'])) {
        foreach ($rdfData[$subject]['http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#hasLocation'] as $locObj) {
            if ($locObj['type'] === 'uri' && isset($rdfData[$locObj['value']])) {
                $locationUri = $locObj['value'];
   
                
                // Extract location name
                if (isset($rdfData[$locationUri]['http://dbpedia.org/ontology/informationName'])) {
                    foreach ($rdfData[$locationUri]['http://dbpedia.org/ontology/informationName'] as $nameObj) {
                        if ($nameObj['type'] === 'literal') {
                            $locationName = $nameObj['value'];
                            
                            if (!isset($itemData['Location Name'])) {
                                $itemData['Location Name'] = [];
                            }
                            
                            $itemData['Location Name'][] = [
                                'type' => 'literal',
                                'property_id' => 1811, 
                                '@value' => $locationName
                            ];
                            
   
                        }
                    }
                }
                
                
                $lat = null;
                $long = null;
                
                if (isset($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
                    foreach ($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
                        if ($latObj['type'] === 'literal') {
                            $lat = $latObj['value'];
   
                        }
                    }
                }
                
                if (isset($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
                    foreach ($rdfData[$locationUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
                        if ($longObj['type'] === 'literal') {
                            $long = $longObj['value'];
   
                        }
                    }
                }
                
                if ($lat !== null) {
                    if (!isset($itemData['GPS Latitude'])) {
                        $itemData['GPS Latitude'] = [];
                    }
                    $itemData['GPS Latitude'][] = [
                        'type' => 'literal',
                        'property_id' => 257, 
                        '@value' => $lat
                    ];
                }
                
                if ($long !== null) {
                    if (!isset($itemData['GPS Longitude'])) {
                        $itemData['GPS Longitude'] = [];
                    }
                    $itemData['GPS Longitude'][] = [
                        'type' => 'literal',
                        'property_id' => 259, 
                        '@value' => $long
                    ];
                }
                
                // Add combined GPS coordinates
                if ($lat !== null && $long !== null) {
                    if (!isset($itemData['GPS Coordinates'])) {
                        $itemData['GPS Coordinates'] = [];
                    }
                    $itemData['GPS Coordinates'][] = [
                        'type' => 'literal',
                        'property_id' => 7664, 
                        '@value' => "Latitude: $lat, Longitude: $long"
                    ];
                    
   
                }
                
                $locationProperties = [
                    'http://dbpedia.org/ontology/district' => ['district', 1555],  
                    'http://dbpedia.org/ontology/parish' => ['parish', 1681],      
                    'http://dbpedia.org/ontology/Country' => ['Country', 1402]  
                ];
                
                foreach ($locationProperties as $propertyUri => $propertyInfo) {
                    if (isset($rdfData[$locationUri][$propertyUri])) {
                        $propertyLabel = $propertyInfo[0];
                        $propertyId = $propertyInfo[1];
                        
   
                        
                        foreach ($rdfData[$locationUri][$propertyUri] as $propObj) {
                            if ($propObj['type'] === 'uri') {
                                $parts = explode('/', $propObj['value']);
                                $value = str_replace('_', ' ', end($parts));
                                
                                if (isset($rdfData[$propObj['value']])) {
                                    $referencedEntity = $rdfData[$propObj['value']];
                                    $nameProperties = [
                                        'http://www.w3.org/2000/01/rdf-schema#label',
                                        'http://dbpedia.org/ontology/name',
                                        'http://purl.org/dc/terms/title'
                                    ];
                                    
                                    foreach ($nameProperties as $nameProp) {
                                        if (isset($referencedEntity[$nameProp])) {
                                            foreach ($referencedEntity[$nameProp] as $nameObj) {
                                                if ($nameObj['type'] === 'literal') {
                                                    $value = $nameObj['value'];
                                                    break 2;
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                if (!isset($itemData[$propertyLabel])) {
                                    $itemData[$propertyLabel] = [];
                                }
                                
                                $itemData[$propertyLabel][] = [
                                    'type' => 'literal',
                                    'property_id' => $propertyId,
                                    '@value' => $value
                                ];
                                
   
                            }
                        }
                    } else {
   
                    }
                }
            }
        }
    }

    $archaeologistPropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasPersonInCharge'
    ];
    



    
if (isset($rdfData[$locationUri]['https://purl.org/megalod/ms/excavation/hasGPSCoordinates']) ||
    isset($rdfData[$locationUri]["http://localhost/megalod/$currentItemSetId/excavation/hasGPSCoordinates"])) {
    
$gpsPropertyUris = [
    'https://purl.org/megalod/ms/excavation/hasGPSCoordinates',
    "http://localhost/megalod/$currentItemSetId/excavation/hasGPSCoordinates",
    'excav:hasGPSCoordinates' 
];
    
    foreach ($gpsPropertyUris as $gpsPropertyUri) {
        if (isset($rdfData[$locationUri][$gpsPropertyUri])) {
            foreach ($rdfData[$locationUri][$gpsPropertyUri] as $gpsObj) {
                if ($gpsObj['type'] === 'uri' && isset($rdfData[$gpsObj['value']])) {
                    $gpsUri = $gpsObj['value'];
                    
                    if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
                        foreach ($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
                            if ($latObj['type'] === 'literal') {
                                $lat = $latObj['value'];
   
                                
                                if (!isset($itemData['GPS Latitude'])) {
                                    $itemData['GPS Latitude'] = [];
                                }
                                $itemData['GPS Latitude'][] = [
                                    'type' => 'literal',
                                    'property_id' => 257, 
                                    '@value' => $lat
                                ];
                            }
                        }
                    }
                    
                    if (isset($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
                        foreach ($rdfData[$gpsUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
                            if ($longObj['type'] === 'literal') {
                                $long = $longObj['value'];
   
                                
                                if (!isset($itemData['GPS Longitude'])) {
                                    $itemData['GPS Longitude'] = [];
                                }
                                $itemData['GPS Longitude'][] = [
                                    'type' => 'literal',
                                    'property_id' => 259, 
                                    '@value' => $long
                                ];
                            }
                        }
                    }
                    
                    if (isset($lat) && isset($long)) {
                        if (!isset($itemData['GPS Coordinates'])) {
                            $itemData['GPS Coordinates'] = [];
                        }
                        $itemData['GPS Coordinates'][] = [
                            'type' => 'literal',
                            'property_id' => 7664, 
                            '@value' => "Latitude: $lat, Longitude: $long"
                        ];
                        
   
                    }
                }
            }
            break;
        }
    }
}
    
    if ($currentItemSetId) {
        $archaeologistPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasPersonInCharge";
    }
    
    foreach ($archaeologistPropertyUris as $archaeologistPropertyUri) {
        if (isset($rdfData[$subject][$archaeologistPropertyUri])) {
   
            
            foreach ($rdfData[$subject][$archaeologistPropertyUri] as $archaeologistObj) {
                if ($archaeologistObj['type'] === 'uri' && isset($rdfData[$archaeologistObj['value']])) {
                    $archaeologistUri = $archaeologistObj['value'];
   
                    
                    // Extract archaeologist data
                    $archaeologistData = $this->extractArchaeologistData($rdfData, $archaeologistUri);
                    
                    if ($archaeologistData) {
                        // Add archaeologist name
                        if ($archaeologistData['name']) {
                            if (!isset($itemData['Archaeologist Name'])) {
                                $itemData['Archaeologist Name'] = [];
                            }
                            
                            $itemData['Archaeologist Name'][] = [
                                'type' => 'literal',
                                'property_id' => 7665, 
                                '@value' => $archaeologistData['name']
                            ];
                            
   
                        }
                        
                        // Add ORCID if available
                        if ($archaeologistData['orcid']) {
                            if (!isset($itemData['Archaeologist ORCID'])) {
                                $itemData['Archaeologist ORCID'] = [];
                            }
                            
                            $itemData['Archaeologist ORCID'][] = [
                                'type' => 'literal',
                                'property_id' => 176,
                                '@value' => $archaeologistData['orcid']
                            ];
                            
   
                        }
                        
                        // Add email if available
                        if ($archaeologistData['email']) {
                            if (!isset($itemData['Archaeologist Email'])) {
                                $itemData['Archaeologist Email'] = [];
                            }
                            
                            $itemData['Archaeologist Email'][] = [
                                'type' => 'literal',
                                'property_id' => 123, 
                                '@value' => $archaeologistData['email']
                            ];
                            
   
                        }
                        
                        if (!isset($itemData['Person in Charge'])) {
                            $itemData['Person in Charge'] = [];
                        }
                        
                        $personInfo = $archaeologistData['name'] ?: $archaeologistData['orcid'];
                        if ($archaeologistData['name'] && $archaeologistData['orcid']) {
                            $personInfo = $archaeologistData['name'] . ' (ORCID: ' . $archaeologistData['orcid'] . ')';
                        }
                        
                        $itemData['Person in Charge'][] = [
                            'type' => 'literal',
                            'property_id' => 7665,
                            '@value' => $personInfo
                        ];
                    }
                }
            }
            break;
        }
    }
    
    $contextList = [];
    $contextPropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasContext'
    ];
    
    if ($currentItemSetId) {
        $contextPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasContext";
    }
    
    foreach ($contextPropertyUris as $contextPropertyUri) {
        if (isset($rdfData[$subject][$contextPropertyUri])) {
   
            
            foreach ($rdfData[$subject][$contextPropertyUri] as $contextObj) {
                if ($contextObj['type'] === 'uri') {
                    $contextId = $this->extractResourceIdentifier($rdfData, $contextObj['value']);
                    if ($contextId) {
                        $contextList[] = $contextId;
                        
                        if (isset($rdfData[$contextObj['value']])) {
                            $contextDesc = $this->extractContextDescription($rdfData, $contextObj['value']);
                            if ($contextDesc) {
                                $contextList[count($contextList) - 1] = "$contextId: $contextDesc";
                            }
                        }
                    }
                }
            }
            break;
        }
    }
    
    if (!empty($contextList)) {
        if (!isset($itemData['Excavation Contexts'])) {
            $itemData['Excavation Contexts'] = [];
        }
        
        $itemData['Excavation Contexts'][] = [
            'type' => 'literal',
            'property_id' => 7666,
            '@value' => implode(' | ', $contextList)
        ];
        
   
    }

    // process svu data
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasSVU'] as $svuObj) {
            if ($svuObj['type'] === 'uri' && isset($rdfData[$svuObj['value']])) {
                $svuUri = $svuObj['value'];
   
                
                // Extract SVU data
                $svuData = $this->extractSvuData($rdfData, $svuUri);
                
                if ($svuData) {
                    // Add SVU name
                    if ($svuData['name']) {
                        if (!isset($itemData['SVU Name'])) {
                            $itemData['SVU Name'] = [];
                        }
                        
                        $itemData['SVU Name'][] = [
                            'type' => 'literal',
                            'property_id' => 7667, 
                            '@value' => $svuData['name']
                        ];
                        
   
                    }
                    
                    // Add SVU description
                    if ($svuData['description']) {
                        if (!isset($itemData['SVU Description'])) {
                            $itemData['SVU Description'] = [];
                        }
                        
                        $itemData['SVU Description'][] = [
                            'type' => 'literal',
                            'property_id' => 7669, 
                            '@value' => $svuData['description']
                        ];
                        
   
                    }
                }
            }
        }
    }
    
    $squareList = [];
    $squarePropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasSquare'
    ];
    
    if ($currentItemSetId) {
        $squarePropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasSquare";
    }
    
    foreach ($squarePropertyUris as $squarePropertyUri) {
        if (isset($rdfData[$subject][$squarePropertyUri])) {
   
            
            foreach ($rdfData[$subject][$squarePropertyUri] as $squareObj) {
                if ($squareObj['type'] === 'uri') {
                    $squareId = $this->extractResourceIdentifier($rdfData, $squareObj['value']);
                    if ($squareId) {
                        $squareList[] = $squareId;
                        
                        if (isset($rdfData[$squareObj['value']])) {
                            $squareCoords = $this->extractSquareCoordinates($rdfData, $squareObj['value']);
                            if ($squareCoords) {
                                $squareList[count($squareList) - 1] = "$squareId ($squareCoords)";
                            }
                        }
                    }
                }
            }
            break;
        }
    }
    
    if (!empty($squareList)) {
        if (!isset($itemData['Excavation Squares'])) {
            $itemData['Excavation Squares'] = [];
        }
        
        $itemData['Excavation Squares'][] = [
            'type' => 'literal',
            'property_id' => 7668,
            '@value' => implode(' | ', $squareList)
        ];
        
   
    }
    
   
}


/**
 * This method extracts the description of a context from RDF data.
 * @param mixed $rdfData
 * @param mixed $contextUri
 */
private function extractContextDescription($rdfData, $contextUri) {
    if (isset($rdfData[$contextUri]['http://purl.org/dc/terms/description'])) {
        foreach ($rdfData[$contextUri]['http://purl.org/dc/terms/description'] as $descObj) {
            if ($descObj['type'] === 'literal') {
                return $descObj['value'];
            }
        }
    }
    return null;
}


/**
 * Extract the square coordinates from the RDF data.
 * @param mixed $rdfData
 * @param mixed $squareUri
 */
private function extractSquareCoordinates($rdfData, $squareUri) {
    $coords = [];
    
    if (isset($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'])) {
        foreach ($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#lat'] as $latObj) {
            if ($latObj['type'] === 'literal') {
                $coords[] = 'Lat: ' . $latObj['value'];
            }
        }
    }
    
    if (isset($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'])) {
        foreach ($rdfData[$squareUri]['http://www.w3.org/2003/01/geo/wgs84_pos#long'] as $longObj) {
            if ($longObj['type'] === 'literal') {
                $coords[] = 'Long: ' . $longObj['value'];
            }
        }
    }
    
    return !empty($coords) ? implode(', ', $coords) : null;
}


/**
 * Extract the square coordinates from the RDF data.
 * @param mixed $rdfData
 * @param mixed $subject
 * @param mixed $itemData
 * @return void
 */
private function processSquareData($rdfData, $subject, &$itemData) {
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['Square ID', 10],
        'http://www.w3.org/2003/01/geo/wgs84_pos#long' => ['North-South Quota', 259],
        'http://www.w3.org/2003/01/geo/wgs84_pos#lat' => ['East-West Quota', 257]
    ];
    
    foreach ($propertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                }
            }
        }
    }
    
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInExcavation'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInExcavation'] as $excObj) {
            if ($excObj['type'] === 'uri') {
                if (!isset($itemData['The Encounter Event - an item found in an Excavation'])) {
                    $itemData['The Encounter Event - an item found in an Excavation'] = [];
                }
                
                $itemData['The Encounter Event - an item found in an Excavation'][] = [
                    'type' => 'uri',
                    'property_id' => 7673, 
                    '@id' => $excObj['value'],
                    '@value' => $excObj['value'] 
                ];
            }
        }
    }
    
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInContext'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInContext'] as $ctxObj) {
            if ($ctxObj['type'] === 'uri') {
                if (!isset($itemData['The Encounter Event - an item found in a specific Context'])) {
                    $itemData['The Encounter Event - an item found in a specific Context'] = [];
                }
                
                $itemData['The Encounter Event - an item found in a specific Context'][] = [
                    'type' => 'uri',
                    'property_id' => 7672, 
                    '@id' => $ctxObj['value'],
                    '@value' => $ctxObj['value'] 
                ];
            }
        }
    }
    
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSVU'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSVU'] as $svuObj) {
            if ($svuObj['type'] === 'uri') {
                if (!isset($itemData['Stratigraphic Unit'])) {
                    $itemData['Stratigraphic Unit'] = [];
                }
                
                $itemData['Stratigraphic Unit'][] = [
                    'type' => 'uri',
                    'property_id' => 7671, 
                    '@id' => $svuObj['value'],
                    '@value' => $svuObj['value'] 
                ];
            }
        }
    }
    
    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSquare'])) {
        foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/foundInSquare'] as $squareObj) {
            if ($squareObj['type'] === 'uri') {
                if (!isset($itemData['Excavation Square'])) {
                    $itemData['Excavation Square'] = [];
                }
                
                $itemData['Excavation Square'][] = [
                    'type' => 'uri',
                    'property_id' => 7668, 
                    '@id' => $squareObj['value'],
                    '@value' => $squareObj['value'] 
                ];
            }
        }
    }
}


/**
 * Extracts archaeologist data from RDF data.
 * This method retrieves the name, ORCID, and email of the archaeologist from the RDF data.
 * @param array $rdfData The RDF data containing archaeologist information
 * @param string $archaeologistUri The URI of the archaeologist to extract data for
 * @return array|null An associative array with archaeologist data or null if not found
 */
private function extractArchaeologistData($rdfData, $archaeologistUri) {
    $data = [
        'name' => null,
        'orcid' => null,
        'email' => null
    ];
    
    if (isset($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/name'])) {
        foreach ($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/name'] as $nameObj) {
            if ($nameObj['type'] === 'literal') {
                $data['name'] = $nameObj['value'];
                break;
            }
        }
    }
    
    if (isset($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/account'])) {
        foreach ($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/account'] as $accountObj) {
            if ($accountObj['type'] === 'uri') {
                $orcidUrl = $accountObj['value'];
                if (strpos($orcidUrl, 'orcid.org') !== false) {
                    $parts = explode('/', $orcidUrl);
                    $data['orcid'] = end($parts);
                    break;
                }
            }
        }
    }
    
    if (isset($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/mbox'])) {
        foreach ($rdfData[$archaeologistUri]['http://xmlns.com/foaf/0.1/mbox'] as $emailObj) {
            if ($emailObj['type'] === 'uri') {
                $emailUrl = $emailObj['value'];
                if (strpos($emailUrl, 'mailto:') === 0) {
                    $data['email'] = substr($emailUrl, 7);
                    break;
                }
            }
        }
    }
    
    return ($data['name'] || $data['orcid']) ? $data : null;
}




/**
 * Processes SVU data from RDF and populates item data.
 * This method extracts SVU ID, description, and other relevant information from RDF data.
 * @param array $rdfData The RDF data containing SVU information
 * @param string $subject The subject URI to process
 * @param array &$itemData The item data to populate with extracted information
 */
private function processSVUData($rdfData, $subject, &$itemData) {
   
   
    
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['SVU ID', 10],
        'http://purl.org/dc/terms/description' => ['Description', 4],
    ];
    
    foreach ($propertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                    
   
                }
            }
        } else {
   
        }
    }
    
    $timelinePropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasTimeline'
    ];
    
    if ($currentItemSetId) {
        $timelinePropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasTimeline";
    }
    
    foreach ($timelinePropertyUris as $timelinePropertyUri) {
        if (isset($rdfData[$subject][$timelinePropertyUri])) {
   
            
            foreach ($rdfData[$subject][$timelinePropertyUri] as $timelineObj) {
                if ($timelineObj['type'] === 'uri' && isset($rdfData[$timelineObj['value']])) {
                    $timelineUri = $timelineObj['value'];
   
                    
                    // Extract beginning and end points
                    $beginningYear = null;
                    $beginningBC = null;
                    $endYear = null;
                    $endBC = null;
                    
                    // Extract beginning
                    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'])) {
                        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'] as $beginObj) {
                            if ($beginObj['type'] === 'uri' && isset($rdfData[$beginObj['value']])) {
                                $beginUri = $beginObj['value'];
   
                                
                                // Extract year
                                if (isset($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                                    foreach ($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                                        if ($yearObj['type'] === 'literal') {
                                            $beginningYear = abs((int)$yearObj['value']); 
   
                                        }
                                    }
                                }
                                
                                $bcadPropertyUris = [
                                    'https://purl.org/megalod/ms/excavation/bcad'
                                ];
                                if ($currentItemSetId) {
                                    $bcadPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/bcad";
                                }
                                
                                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                                    if (isset($rdfData[$beginUri][$bcadPropertyUri])) {
                                        foreach ($rdfData[$beginUri][$bcadPropertyUri] as $bcObj) {
                                            if ($bcObj['type'] === 'uri') {
                                                $parts = explode('/', $bcObj['value']);
                                                $bcacValue = end($parts);
                                                $beginningBC = ($bcacValue === 'BC');
   
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'])) {
                        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'] as $endObj) {
                            if ($endObj['type'] === 'uri' && isset($rdfData[$endObj['value']])) {
                                $endUri = $endObj['value'];
   
                                
                                if (isset($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                                    foreach ($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                                        if ($yearObj['type'] === 'literal') {
                                            $endYear = abs((int)$yearObj['value']); 
   
                                        }
                                    }
                                }
                                
                                // Extract BC/AD
                                $bcadPropertyUris = [
                                    'https://purl.org/megalod/ms/excavation/bcad'
                                ];
                                if ($currentItemSetId) {
                                    $bcadPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/bcad";
                                }
                                
                                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                                    if (isset($rdfData[$endUri][$bcadPropertyUri])) {
                                        foreach ($rdfData[$endUri][$bcadPropertyUri] as $bcObj) {
                                            if ($bcObj['type'] === 'uri') {
                                                $parts = explode('/', $bcObj['value']);
                                                $bcacValue = end($parts);
                                                $endBC = ($bcacValue === 'BC');
   
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    
                    
                    // combined timeline range
                    if ($beginningYear && $endYear) {
                        if (!isset($itemData['Chronological Period'])) {
                            $itemData['Chronological Period'] = [];
                        }
                        
                        $beginText = $beginningYear . ($beginningBC ? ' BC' : ' AD');
                        $endText = $endYear . ($endBC ? ' BC' : ' AD');
                        $timelineRange = "$beginText - $endText";
                        
                        $itemData['Chronological Period'][] = [
                            'type' => 'literal',
                            'property_id' => 7669, 
                            '@value' => $timelineRange
                        ];
                        
   
                    }
                }
            }
            break; 
        } else {
   
        }
    }

    if (isset($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasTimeline'])) {
    foreach ($rdfData[$subject]['https://purl.org/megalod/ms/excavation/hasTimeline'] as $timelineObj) {
        if ($timelineObj['type'] === 'uri' && isset($rdfData[$timelineObj['value']])) {
            $timelineUri = $timelineObj['value'];
            
            $timelineRange = $this->extractTimelineRange($rdfData, $timelineUri);
            if ($timelineRange) {
                if (!isset($itemData['Chronological Period'])) {
                    $itemData['Chronological Period'] = [];
                }
                
                $itemData['Chronological Period'][] = [
                    'type' => 'literal',
                    'property_id' => 7669,
                    '@value' => $timelineRange
                ];
            }
        }
    }
}
    
   
}



/**
 * Processes context data from RDF and populates item data.
 * This method extracts context ID, description, and linked SVUs from RDF data.
 * @param array $rdfData The RDF data containing context information
 * @param string $subject The subject URI to process
 * @param array &$itemData The item data to populate with extracted information
 */
private function processContextData($rdfData, $subject, &$itemData) {
   
   
    
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    $propertyMap = [
        'http://purl.org/dc/terms/identifier' => ['Context ID', 10],
        'http://purl.org/dc/terms/description' => ['Context Description', 4],
    ];
    
    foreach ($propertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $object['value']
                    ];
                    
   
                }
            }
        }
    }
    
    $svuPropertyUris = [
        'https://purl.org/megalod/ms/excavation/hasSVU'
    ];
    
    if ($currentItemSetId) {
        $svuPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasSVU";
    }
    
    $linkedSVUs = [];
    $svuDetails = [];
    
    foreach ($svuPropertyUris as $svuPropertyUri) {
        if (isset($rdfData[$subject][$svuPropertyUri])) {
   
            
            foreach ($rdfData[$subject][$svuPropertyUri] as $svuObj) {
                if ($svuObj['type'] === 'uri') {
                    $svuUri = $svuObj['value'];
                    $svuId = $this->extractResourceIdentifier($rdfData, $svuUri);
                    
                    if ($svuId) {
                        $linkedSVUs[] = $svuId;
                        
                        if (isset($rdfData[$svuUri])) {
                            $svuDescription = null;
                            $svuTimeline = null;
                            
                            // Get SVU description
                            if (isset($rdfData[$svuUri]['http://purl.org/dc/terms/description'])) {
                                foreach ($rdfData[$svuUri]['http://purl.org/dc/terms/description'] as $descObj) {
                                    if ($descObj['type'] === 'literal') {
                                        $svuDescription = $descObj['value'];
                                        break;
                                    }
                                }
                            }
                            
                            // Get timeline information
                            $timelinePropertyUris = [
                                'https://purl.org/megalod/ms/excavation/hasTimeline'
                            ];
                            if ($currentItemSetId) {
                                $timelinePropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/hasTimeline";
                            }
                            
                            foreach ($timelinePropertyUris as $timelinePropertyUri) {
                                if (isset($rdfData[$svuUri][$timelinePropertyUri])) {
                                    foreach ($rdfData[$svuUri][$timelinePropertyUri] as $timelineObj) {
                                        if ($timelineObj['type'] === 'uri' && isset($rdfData[$timelineObj['value']])) {
                                            $timelineRange = $this->extractTimelineRange($rdfData, $timelineObj['value']);
                                            if ($timelineRange) {
                                                $svuTimeline = $timelineRange;
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                            
                            $svuDetail = $svuId;
                            if ($svuDescription) {
                                $svuDetail .= ": $svuDescription";
                            }
                            if ($svuTimeline) {
                                $svuDetail .= " ($svuTimeline)";
                            }
                            
                            $svuDetails[] = $svuDetail;
                            
   
                        } else {
                            $svuDetails[] = $svuId;
   
                        }
                    }
                }
            }
            break; 
        }
    }
    
    if (!empty($linkedSVUs)) {
        if (!isset($itemData['Linked Stratigraphic Units'])) {
            $itemData['Linked Stratigraphic Units'] = [];
        }
        
        $itemData['Linked Stratigraphic Units'][] = [
            'type' => 'literal',
            'property_id' => 7667,
            '@value' => implode(', ', $linkedSVUs)
        ];
        
   
    }
    
   
}

/**
 * this method extracts the timeline range from RDF data.
 * It retrieves the beginning and end years, along with BC/AD information,
 * and formats it into a human-readable string.
 * @param mixed $rdfData
 * @param mixed $timelineUri
 * @return string|null
 */
private function extractTimelineRange($rdfData, $timelineUri) {
    if (!isset($rdfData[$timelineUri])) {
        return null;
    }
    
    $beginningYear = null;
    $beginningBC = null;
    $endYear = null;
    $endBC = null;
    
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    // Extract beginning
    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'])) {
        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasBeginning'] as $beginObj) {
            if ($beginObj['type'] === 'uri' && isset($rdfData[$beginObj['value']])) {
                $beginUri = $beginObj['value'];
                
                // Extract year
                if (isset($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                    foreach ($rdfData[$beginUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                        if ($yearObj['type'] === 'literal') {
                            $beginningYear = abs((int)$yearObj['value']); 
                        }
                    }
                }
                
                // Extract BC/AD with normalized URIs
                $bcadPropertyUris = [
                    'https://purl.org/megalod/ms/excavation/bcad'
                ];
                if ($currentItemSetId) {
                    $bcadPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/bcad";
                }
                
                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                    if (isset($rdfData[$beginUri][$bcadPropertyUri])) {
                        foreach ($rdfData[$beginUri][$bcadPropertyUri] as $bcObj) {
                            if ($bcObj['type'] === 'uri') {
                                $parts = explode('/', $bcObj['value']);
                                $bcacValue = end($parts);
                                $beginningBC = ($bcacValue === 'BC');
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    if (isset($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'])) {
        foreach ($rdfData[$timelineUri]['http://www.w3.org/2006/time#hasEnd'] as $endObj) {
            if ($endObj['type'] === 'uri' && isset($rdfData[$endObj['value']])) {
                $endUri = $endObj['value'];
                
                if (isset($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'])) {
                    foreach ($rdfData[$endUri]['http://www.w3.org/2006/time#inXSDgYear'] as $yearObj) {
                        if ($yearObj['type'] === 'literal') {
                            $endYear = abs((int)$yearObj['value']); 
                        }
                    }
                }
                
                $bcadPropertyUris = [
                    'https://purl.org/megalod/ms/excavation/bcad'
                ];
                if ($currentItemSetId) {
                    $bcadPropertyUris[] = "http://localhost/megalod/$currentItemSetId/excavation/bcad";
                }
                
                foreach ($bcadPropertyUris as $bcadPropertyUri) {
                    if (isset($rdfData[$endUri][$bcadPropertyUri])) {
                        foreach ($rdfData[$endUri][$bcadPropertyUri] as $bcObj) {
                            if ($bcObj['type'] === 'uri') {
                                $parts = explode('/', $bcObj['value']);
                                $bcacValue = end($parts);
                                $endBC = ($bcacValue === 'BC');
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    if ($beginningYear && $endYear) {
        $beginText = $beginningYear . ($beginningBC ? ' BC' : ' AD');
        $endText = $endYear . ($endBC ? ' BC' : ' AD');
        return "$beginText - $endText";
    } else if ($beginningYear) {
        $beginText = $beginningYear . ($beginningBC ? ' BC' : ' AD');
        return "From $beginText";
    } else if ($endYear) {
        $endText = $endYear . ($endBC ? ' BC' : ' AD');
        return "Until $endText";
    }
    
    return null;
}




/**
 * This method extracts common properties from RDF data and populates item data.
 * @param mixed $rdfData
 * @param mixed $subject
 * @param mixed $itemData
 * @return void
 */
private function extractCommonProperties($rdfData, $subject, &$itemData) {
    // Map common predicates to Omeka S properties with correct labels
    $commonPropertyMap = [
        'http://dbpedia.org/ontology/Annotation' => ['Description', 4], // Use description instead
        'http://www.cidoc-crm.org/cidoc-crm/E3_Condition_State' => ['Condition State', 476],
        'http://www.cidoc-crm.org/cidoc-crm/E55_Type' => ['Type', 399],
    ];
    
    
    foreach ($commonPropertyMap as $predicate => $mapping) {
        if (isset($rdfData[$subject][$predicate])) {
            $term = $mapping[0];
            $propertyId = $mapping[1];
            
            if (!isset($itemData[$term])) {
                $itemData[$term] = [];
            }
            
            foreach ($rdfData[$subject][$predicate] as $object) {
                if ($object['type'] === 'literal') {
                    if ($object['value'] === 'true' || $object['value'] === 'false') {
                        $displayValue = ($object['value'] === 'true') ? 'True' : 'False';
                    } else {
                        $displayValue = $object['value'];
                    }
                    
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => $displayValue
                    ];
                } elseif ($object['type'] === 'uri') {
                    if (strpos($object['value'], '/kos/') !== false) {
                        $parts = explode('/', $object['value']);
                        $value = end($parts);
                        
                        $itemData[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $value
                        ];
                    } else {
                        $itemData[$term][] = [
                            'type' => 'uri',
                            'property_id' => $propertyId,
                            '@id' => $object['value'],
                            'o:label' => $object['value']
                        ];
                    }
                }
            }
        }
    }
}


/**
 * This method determines the item type based on the subject type.
 * @param string $subjectType The type of the subject (e.g., 'arrowhead', 'item', etc.)
 * @return string The corresponding item type for Omeka S
 */
private function determineItemType($subjectType) {
    $typeMap = [
        'arrowhead' => 'Arrowhead',
        'item' => 'Archaeological Item', 
        'excavation' => 'Excavation',
        'context' => 'Context',
        'svu' => 'Stratigraphic Unit',
        'square' => 'Square',
        'unknown' => 'Archaeological Object'
    ];
    
    return $typeMap[$subjectType] ?? 'Archaeological Object';
}

/**
 * This method sends the item data to Omeka S and handles duplicates.
 * It checks for duplicate identifiers within the current batch and against existing items in the item set.
 * @param array $omekaData The data to send to Omeka S
 * @param int|null $itemSetId The ID of the item set to check for existing items
 * @return array An array containing errors, created items, and skipped items
 */
private function sendToOmekaS($omekaData, $itemSetId = null) {
    $omekaBaseUrl = 'http://localhost/api';
    $omekaKeyIdentity = '2TGK0xT9tEMCUQs1178OyCnyRcIQpv5B';
    $omekaKeyCredential = '9IFd207Y8D5yG1bmtnCllmbgZweuMfQA';
    $omekaUser = 1;

    $client = new Client();
    $client->setMethod('POST');
    $client->setHeaders([
        'Content-Type' => 'application/json',
        'Omeka-S-Api-Key' => $omekaUser,
    ]);

    $errors = [];
    $createdItems = [];
    $skippedItems = [];
    
    $identifierMap = [];
    $duplicatesInBatch = [];
    
    foreach ($omekaData as $itemIndex => $itemData) {
        $identifier = $this->extractIdentifierFromItemData($itemData);
        if ($identifier) {
            if (isset($identifierMap[$identifier])) {
                $duplicatesInBatch[] = $identifier;
                $errors[] = "Duplicate identifier '$identifier' found in the current batch (items $identifierMap[$identifier] and $itemIndex)";
            } else {
                $identifierMap[$identifier] = $itemIndex;
            }
        }
    }
    
    // Process each item
    foreach ($omekaData as $itemIndex => $itemData) {
        $identifier = $this->extractIdentifierFromItemData($itemData);
        
        if ($identifier && in_array($identifier, $duplicatesInBatch)) {
            $skippedItems[] = [
                'index' => $itemIndex,
                'identifier' => $identifier,
                'reason' => 'Duplicate identifier in current batch'
            ];
            continue;
        }
        
        // Check if item with this identifier already exists in the item set
        if ($identifier && $itemSetId && $this->itemExistsWithIdentifier($identifier, $itemSetId)) {
            $skippedItems[] = [
                'index' => $itemIndex,
                'identifier' => $identifier,
                'reason' => 'Item with this identifier already exists in the item set'
            ];
            $errors[] = "Skipped item $itemIndex: An item with identifier '$identifier' already exists in item set #$itemSetId";
            continue;
        }

        $fullUrl = rtrim($omekaBaseUrl, '/') . '/items' . 
                   '?key_identity=' . urlencode($omekaKeyIdentity) .
                   '&key_credential=' . urlencode($omekaKeyCredential);
        
        $client->setUri($fullUrl);
        $client->setRawBody(json_encode($itemData));
        $response = $client->send();

        if (!$response->isSuccess()) {
            $errors[] = 'Failed to create item ' . ($itemIndex + 1) . ': ' . 
                         $response->getStatusCode() . ' - ' . $response->getBody();
   
        } else {
            $createdItem = json_decode($response->getBody(), true);
            if ($createdItem && isset($createdItem['o:id'])) { 
                $itemId = $createdItem['o:id'];
                $this->attachMediaToItem($createdItem['o:id']);
                
                                
            } else {
                
                $itemId = null; 
   
            }
            
            $this->attachMediaToItem($itemId);
            
            $createdItems[] = $createdItem;
   
        }
    }

    if ($itemSetId && !empty($createdItems) && $this->excavationData) {
        $this->updateItemSetWithExcavationInfo($itemSetId, $this->excavationData);
    }

    if (!empty($skippedItems)) {
   
    }

    return [
        'errors' => $errors,
        'created_items' => $createdItems,
        'skipped_items' => $skippedItems
    ];
}
/**
 * This method checks if an item with the given identifier already exists in the specified item set.
 * It searches for items with the identifier and returns true if found, false otherwise.
 * @param string $identifier The identifier to check for
 * @param int $itemSetId The ID of the item set to search in
 * @return bool True if an item with the identifier exists, false otherwise
 */
private function itemExistsWithIdentifier($identifier, $itemSetId) {
    try {
   
        $searchParams = [
            'property' => [
                [
                    'property' => 10,
                    'type' => 'eq',
                    'text' => $identifier
                ]
            ],
            'item_set_id' => $itemSetId,
            'limit' => 1
        ];
        
        $response = $this->api()->search('items', $searchParams);
        $totalItems = $response->getTotalResults();
        
        if ($totalItems > 0) {
            $items = $response->getContent();
            $existingItem = $items[0];
   
            return true;
        }
        
   
        return false;
    } catch (\Exception $e) {
   
        return false; 
    }
}

/**
 * This method extracts the identifier from item data.
 * @param mixed $itemData
 * @return string|null The identifier value if found, null otherwise
 */
private function extractIdentifierFromItemData($itemData) {
    if (isset($itemData['dcterms:identifier'])) {
        foreach ($itemData['dcterms:identifier'] as $identifierData) {
            if (isset($identifierData['@value'])) {
                return $identifierData['@value'];
            }
        }
    }
    return null;
}
/**
 * this method attaches media files to the created item in Omeka S.
 * @param mixed $itemId
 * @return void
 */
private function attachMediaToItem($itemId) {
   
    
    if ($this->uploadedFiles && isset($this->uploadedFiles['name']) && is_array($this->uploadedFiles['name'])) {
   
        
        for ($i = 0; $i < count($this->uploadedFiles['name']); $i++) {
            if ($this->uploadedFiles['error'][$i] === UPLOAD_ERR_OK) {
                $tempFile = $this->uploadedFiles['tmp_name'][$i];
                $filename = $this->uploadedFiles['name'][$i];
                $mimeType = $this->uploadedFiles['type'][$i];
                
   
                
                try {
                    // Create the media via Omeka API
                    $mediaData = [
                        'o:ingester' => 'upload',
                        'o:item' => ['o:id' => $itemId],
                        'dcterms:title' => [
                            [
                                'type' => 'literal',
                                'property_id' => 1, 
                                '@value' => $filename
                            ]
                        ]
                    ];
                    
                    $tempDir = sys_get_temp_dir();
                    $targetPath = $tempDir . '/' . uniqid('omeka_upload_') . '_' . basename($filename);
                    if (copy($tempFile, $targetPath)) {
   
                        
                        $_FILES = [
                            'file' => [
                                'name' => [$filename],
                                'type' => [$mimeType],
                                'tmp_name' => [$targetPath],
                                'error' => [0],
                                'size' => [filesize($tempFile)]
                            ]
                        ];
                        
                        $response = $this->api()->create('media', $mediaData);
   
                    } else {
   
                    }
                } catch (\Exception $e) {
   
                }
            } else {
   
            }
        }
    }
}


/**
 * This method retrieves the archaeologist options from the RDF data.
 * It queries for archaeologists associated with excavations and returns their names and ORCID IDs.
 * @return array An array of archaeologist options with names and ORCID IDs
 */
private function getArchaeologistOptions()
{
    $query = "
    PREFIX foaf: <http://xmlns.com/foaf/0.1/>
    PREFIX excav: <https://purl.org/megalod/ms/excavation/>
    
    SELECT DISTINCT ?name ?orcid
    WHERE {
        ?excavation a excav:Excavation .
        ?excavation excav:hasPersonInCharge ?archaeologist .
        ?archaeologist foaf:name ?name .
        OPTIONAL { 
            ?archaeologist foaf:account ?orcidUri .
            FILTER(CONTAINS(STR(?orcidUri), 'orcid.org'))
            BIND(REPLACE(STR(?orcidUri), '.*/([0-9X-]+)$', '$1') AS ?orcid)
        }
    }
    ORDER BY ?name
    ";
    
    try {
        $results = $this->executeGraphDbQuery($query);
        
        $archaeologists = [];
        if (!empty($results) && isset($results['results']['bindings'])) {
            foreach ($results['results']['bindings'] as $result) {
                $archaeologists[] = [
                    'name' => $result['name']['value'] ?? '',
                    'orcid' => isset($result['orcid']) ? $result['orcid']['value'] : null
                ];
            }
        }
        
   
        
        return $archaeologists;
    } catch (\Exception $e) {
   
        return [];
    }
}


/**
 * This method retrieves the country options from the RDF data.
 * It queries for countries and returns their names.
 * @return array An array of country names
 */
private function getCountryOptions()
{
    $query = "

    PREFIX dbo: <http://dbpedia.org/ontology/>
PREFIX excav: <https://purl.org/megalod/ms/excavation/>

SELECT DISTINCT ?countryName
WHERE {
  ?location a excav:Location ;
            dbo:Country ?country .
  BIND(REPLACE(STR(?country), 'http://dbpedia.org/resource/', '') AS ?countryName)
}
ORDER BY ?countryName
    ";
    ;
    
    try {
        $results = $this->executeGraphDbQuery($query);
        
        $countries = [];
        if (!empty($results) && isset($results['results']['bindings'])) {
            foreach ($results['results']['bindings'] as $result) {
                if (isset($result['countryName'])) {
                    $countries[] = $result['countryName']['value'];
                }
            }
        }
        
        if (empty($countries)) {
            $countries = [];
        }
        
        // Debug log
   
        
        return $countries;
    } catch (\Exception $e) {
   
        return [];
    }
}

/**
 * This method retrieves the district options from the RDF data.
 * It queries for districts and returns their names.
 * @return array An array of district names
 */
private function getDistrictOptions()
{
    $query = "
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX dbo: <http://dbpedia.org/ontology/>

SELECT DISTINCT ?districtName
WHERE {
  ?district rdf:type dbo:District .
  BIND(REPLACE(STR(?district), 'http://dbpedia.org/resource/', '') AS ?districtName)}
    ";
    
    try {
        $results = $this->executeGraphDbQuery($query);
        
        $districts = [];
        if (!empty($results) && isset($results['results']['bindings'])) {
            foreach ($results['results']['bindings'] as $result) {
                if (isset($result['districtName'])) {
                    $districts[] = $result['districtName']['value'];
                }
            }
        }
        
   
        
        return $districts;
    } catch (\Exception $e) {
   
        return [];
    }
}
/**
 * This method retrieves the parish options from the RDF data.
 * It queries for parishes and returns their names.
 * @return array An array of parish names
 */
private function getParishOptions()
{
    $query = "
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX dbo: <http://dbpedia.org/ontology/>

SELECT DISTINCT ?parishName
WHERE {
  ?parish rdf:type dbo:Parish .
  BIND(REPLACE(STR(?parish), 'http://dbpedia.org/resource/', '') AS ?parishName)}
    ";
    
    try {
        $results = $this->executeGraphDbQuery($query);
        
        $parishes = [];
        if (!empty($results) && isset($results['results']['bindings'])) {
            foreach ($results['results']['bindings'] as $result) {
                if (isset($result['parishName'])) {
                    $parishes[] = $result['parishName']['value'];
                }
            }
        }
        
   
        
        return $parishes;
    } catch (\Exception $e) {
   
        return [];
    }
}


/**
 * this method executes a SPARQL query against the GraphDB endpoint.
 * @param mixed $queryString
 * @return mixed|null
 */
private function executeGraphDbQuery($queryString)
{
    try {
        $client = new \Laminas\Http\Client();
        $client->setUri($this->graphdbQueryEndpoint);
        $client->setMethod('POST');
        
        $credentials = $this->getGraphDBCredentials();
        
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'application/sparql-results+json',
            'Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['password'])
        ]);
        
        $client->setRawBody($queryString);
        
        $response = $client->send();
        
        if ($response->isSuccess()) {
            $results = json_decode($response->getBody(), true);
            return $results;
        } else {
   
            return null;
        }
    } catch (\Exception $e) {
   
        return null;
    }
}





/**
 * This method converts a term to a human-readable label.
 * It maps common terms to readable labels and formats others.
 * @param string $term The term to convert
 * @return string The human-readable label
 */
private function getHumanReadableLabel($term)
{
    // Common property mappings
    $labelMappings = [
        'dcterms:title' => 'Title',
        'dcterms:identifier' => 'Identifier',
        'dcterms:description' => 'Description',
        'bibo:annotates' => 'Annotations',
        'crm:P44_has_condition' => 'Condition',
        'crm:P2_has_type' => 'Type',
        'crm:P43_has_dimension' => 'Dimension',
        'geo:lat' => 'Latitude',
        'geo:long' => 'Longitude',
        'ah:shape' => 'Shape',
        'ah:variant' => 'Variant',
        'ah:hasMorphology' => 'Morphology',
        'excav:elongationIndex' => 'Elongation Index',
        'excav:thicknessIndex' => 'Thickness Index',
        'schema:height' => 'Height',
        'schema:width' => 'Width',
        'schema:depth' => 'Thickness',
        'schema:weight' => 'Weight',
    ];
    
    if (isset($labelMappings[$term])) {
        return $labelMappings[$term];
    }
    

    $label = $term;
    if (strpos($label, ':') !== false) {
        $parts = explode(':', $label);
        $label = end($parts);
    }
    
    $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);
    
    $label = ucwords($label);
    
    return $label;
}
    



/**
 * This method queries the GraphDB for complete excavation data.
 * @param mixed $itemSetId
 * @param mixed $resource
 * @return string|null
 */
private function queryCompleteExcavationFromGraphDB($itemSetId, $resource)
{
    $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
    
   
    
    $query = "
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
    PREFIX sh: <http://www.w3.org/ns/shacl#>
    PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
    PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
    PREFIX dct: <http://purl.org/dc/terms/>
    PREFIX foaf: <http://xmlns.com/foaf/0.1/>
    PREFIX dbo: <http://dbpedia.org/ontology/>
    PREFIX crm: <http://www.cidoc-crm.org/cidoc-crm/>
    PREFIX crmsci: <http://cidoc-crm.org/extensions/crmsci/>
    PREFIX crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>
    PREFIX edm: <http://www.europeana.eu/schemas/edm/>
    PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
    PREFIX time: <http://www.w3.org/2006/time#>
    PREFIX schema: <http://schema.org/>
    PREFIX ah: <https://purl.org/megalod/ms/ah/>
    PREFIX excav: <https://purl.org/megalod/ms/excavation/>
    PREFIX dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#>
    
    CONSTRUCT {
        ?s ?p ?o .
    }
    WHERE {
        GRAPH <$graphUri> {
            ?s ?p ?o .
        }
    }
    ";
    
    $ttlData = $this->executeConstructQuery($query);
    
    if ($ttlData) {
        $organizedTtl = $this->organizeAndFormatTtl($ttlData, $itemSetId);
        
   
        return $organizedTtl;
    }
    
   
    return null;
}



/**
 * This method parses the TTL data into subjects and their statements.
 * @param mixed $ttlData
 * @return array<array<array|string|null>>
 */
private function parseTtlIntoSubjects($ttlData)
{
    $subjects = [];
    $lines = explode("\n", $ttlData);
    $currentSubject = null;
    $currentStatements = [];
    $inStatement = false;

    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if (empty($trimmedLine) || strpos($trimmedLine, '#') === 0) continue;

        if (preg_match('/^(<[^>]+>)\s+(.+)$/', $trimmedLine, $matches) && !$inStatement) {
            if ($currentSubject && !empty($currentStatements)) {
                $subjects[$currentSubject] = $this->cleanStatements($currentStatements);
            }
            $currentSubject = $matches[1];
            $currentStatements = [trim($matches[2])];
            $inStatement = (substr($trimmedLine, -1) !== '.');
        } else if ($currentSubject) {
            $currentStatements[] = $trimmedLine;
            if (substr($trimmedLine, -1) === '.') {
                $subjects[$currentSubject] = $this->cleanStatements($currentStatements);
                $currentSubject = null;
                $currentStatements = [];
                $inStatement = false;
            } else {
                $inStatement = true;
            }
        }
    }
    if ($currentSubject && !empty($currentStatements)) {
        $subjects[$currentSubject] = $this->cleanStatements($currentStatements);
    }
   
    return $subjects;
}

/**
 * This method cleans up the statements by removing unnecessary whitespace,
 * trailing punctuation, and ensuring consistent formatting.
 * @param array $statements
 * @return array
 */
private function cleanStatements($statements)
{
    $cleaned = [];
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        $statement = rtrim($statement, ';.');
        
        $statement = preg_replace('/\s+/', ' ', $statement);
        
        if (!empty($statement)) {
            $cleaned[] = $statement;
        }
    }
    
    return $cleaned;
}
/**
 * This method formats the subject statements into a readable TTL format.
 * It groups predicates and objects, removes empty lines, and formats the output.
 * @param string $subject The subject URI
 * @param array $statements The statements associated with the subject
 * @return string The formatted TTL string for the subject
 */
private function formatSubjectStatements($subject, $statements)
{
    if (empty($statements)) {
        return $subject . " .\n";
    }

    $predicateObjects = [];
    $lastPredicate = null;

    foreach ($statements as $statement) {
        $cleanStatement = trim($statement);
        if (empty($cleanStatement)) continue;
        if (strpos($cleanStatement, 'dct:date') === 0) continue;

        // If line starts with a predicate
        if (preg_match('/^([^\s]+)\s+(.+)$/', $cleanStatement, $matches)) {
            $predicate = $matches[1];
            $object = $matches[2];

            // Filter out foundInSVU and foundInContext for main item
            if (
                ($predicate === 'excav:foundInSVU' || $predicate === 'excav:foundInContext') &&
                preg_match('/a\s+(ah:Arrowhead|excav:Item)/', implode(' ', $statements))
            ) {
                continue;
            }

            $predicateObjects[$predicate][] = $object;
            $lastPredicate = $predicate;
        }
        // If line is just a URI object treat as additional object for previous predicate
        elseif ($lastPredicate && preg_match('/^<[^>]+>$/', $cleanStatement)) {
            $predicateObjects[$lastPredicate][] = $cleanStatement;
        }
    }

    $lines = [];
    foreach ($predicateObjects as $predicate => $objects) {
        // Remove empty and duplicate objects, and filter out empty strings
        $objects = array_filter(array_unique(array_map('trim', $objects)), function($o) {
            return $o !== '' && $o !== ',';
        });
        // Remove any trailing commas from each object
        $objects = array_map(function($o) {
            return rtrim($o, ',');
        }, $objects);
        // Remove any empty objects again after trimming
        $objects = array_filter($objects, function($o) {
            return $o !== '';
        });

        if (count($objects) > 1) {
            $lines[] = "    $predicate " . implode(",\n        ", $objects);
        } elseif (count($objects) === 1) {
            $lines[] = "    $predicate " . reset($objects);
        }
    }

    if (empty($lines)) {
        return $subject . " .\n";
    }

    $ttl = $subject . "\n" . implode(" ;\n", $lines) . " .\n";
    return $ttl;
}
/**
 * This method organizes and formats the raw TTL data into a structured format.
 * It groups statements by resource type and adds appropriate headers.
 * @param string $rawTtlData The raw TTL data as a string
 * @param int $itemSetId The ID of the item set for which the TTL is being organized
 * @return string The organized TTL data
 */
private function organizeAndFormatTtl($rawTtlData, $itemSetId)
{
    $subjects = $this->parseTtlIntoSubjects($rawTtlData);
    
    $organizedTtl = $this->getTtlPrefixes();
    $organizedTtl .= "\n# ========================================================================================\n";
    $organizedTtl .= "# ARCHAEOLOGICAL ITEM DATA - ITEM SET $itemSetId\n";
    $organizedTtl .= "# Downloaded from GraphDB on " . date('Y-m-d H:i:s') . "\n";
    $organizedTtl .= "# Organized by resource type for better readability\n";
    $organizedTtl .= "# ========================================================================================\n\n";
    
    $sections = [
        'excavation' => [
            'title' => 'MAIN EXCAVATION',
            'pattern' => '/a\s+excav:Excavation/'
        ],
        'location' => [
            'title' => 'LOCATION',
            'pattern' => '/a\s+excav:Location/'
        ],
        'gps' => [
            'title' => 'GPS COORDINATES', 
            'pattern' => '/a\s+excav:GPSCoordinates/'
        ],
        'archaeologist' => [
            'title' => 'ARCHAEOLOGIST',
            'pattern' => '/a\s+excav:Archaeologist/'
        ],
        'squares' => [
            'title' => 'EXCAVATION SQUARES',
            'pattern' => '/a\s+excav:Square/'
        ],
        'contexts' => [
            'title' => 'CONTEXTS',
            'pattern' => '/a\s+excav:Context/'
        ],
        'svus' => [
            'title' => 'STRATIGRAPHIC VOLUME UNITS',
            'pattern' => '/a\s+excav:StratigraphicVolumeUnit/'
        ],
        'items' => [
            'title' => 'ARCHAEOLOGICAL ITEMS',
            'pattern' => '/a\s+(ah:Arrowhead|excav:Item)/'
        ],
        'morphology' => [
            'title' => 'MORPHOLOGY',
            'pattern' => '/a\s+ah:Morphology/'
        ],
        'chipping' => [
            'title' => 'CHIPPING',
            'pattern' => '/a\s+ah:Chipping/'
        ],
        'measurements' => [
            'title' => 'MEASUREMENTS',
            'pattern' => '/a\s+(excav:TypometryValue|excav:Weight)/'
        ],
        'coordinates' => [
            'title' => 'COORDINATES',
            'pattern' => '/a\s+excav:Coordinates/'
        ],
        'encounters' => [
            'title' => 'ENCOUNTER EVENTS',
            'pattern' => '/a\s+excav:EncounterEvent/'
        ],
        'timelines' => [
            'title' => 'TIMELINES',
            'pattern' => '/a\s+excav:TimeLine/'
        ],
        'instants' => [
            'title' => 'TIME INSTANTS',
            'pattern' => '/a\s+excav:Instant/'
        ],
        'external' => [
            'title' => 'EXTERNAL REFERENCES',
            'pattern' => '/a\s+(dbo:District|dbo:Parish|dbo:Country)/'
        ]
    ];
    
    // Process each section
    foreach ($sections as $sectionKey => $sectionInfo) {
        $sectionSubjects = $this->findSubjectsByPattern($subjects, $sectionInfo['pattern']);
        
        if (!empty($sectionSubjects)) {
            $organizedTtl .= "# =========== {$sectionInfo['title']} ===========\n\n";
            
            foreach ($sectionSubjects as $subject => $statements) {
                $organizedTtl .= $this->formatSubjectStatements($subject, $statements);
                $organizedTtl .= "\n";
            }
        }
    }
    
    return $organizedTtl;
}
/**
 * This method finds subjects in the TTL data that match a specific pattern.
 * It returns an associative array of subjects and their statements that match the pattern.
 * @param array $subjects The parsed subjects from the TTL data
 * @param string $pattern The regex pattern to match against the subject statements
 * @return array An associative array of matching subjects and their statements
 */
private function findSubjectsByPattern($subjects, $pattern)
{
    $matchingSubjects = [];
    
    foreach ($subjects as $subject => $statements) {
        $allStatements = implode(' ', $statements);
        
        if (preg_match($pattern, $allStatements)) {
            $matchingSubjects[$subject] = $statements;
        }
    }
    
    return $matchingSubjects;
}
/**
 * This method retrieves the TTL prefixes used in the RDF data.
 * It returns a string containing the necessary prefixes for the TTL format.
 * @return string The TTL prefixes
 */
private function cleanExistingPrefixes($ttlData)
{
    $lines = explode("\n", $ttlData);
    $cleanedLines = [];
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if (!empty($trimmedLine) && strpos($trimmedLine, '@prefix') !== 0) {
            $cleanedLines[] = $line;
        }
    }
    
    return implode("\n", $cleanedLines);
}


/**
 * This method queries the GraphDB for a specific item by its ID.
 * @param mixed $resource
 * @param mixed $itemId
 * @return string|null
 */
private function queryItemFromGraphDB($resource, $itemId)
{
    $itemSetId = null;
    
    $itemSets = $resource->itemSets();
    if (!empty($itemSets)) {
        $firstSet = reset($itemSets);
        if ($firstSet) {
            $itemSetId = $firstSet->id();
        }
    }
    
    if (!$itemSetId) {
   
        return null;
    }
    
    $graphUri = "{$this->baseDataGraphUri}{$itemSetId}/";
    
    $values = $resource->values();
    $identifier = null;
    if (isset($values['dcterms:identifier'])) {
        $identifier = $values['dcterms:identifier']['values'][0]->value();
    }
    
    if (!$identifier) {
   
        return null;
    }
    
    $itemUriPattern = "http://localhost/megalod/$itemSetId/item/$identifier";
    
    $query = "
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
    PREFIX sh: <http://www.w3.org/ns/shacl#>
    PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
    PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
    PREFIX dct: <http://purl.org/dc/terms/>
    PREFIX foaf: <http://xmlns.com/foaf/0.1/>
    PREFIX dbo: <http://dbpedia.org/ontology/>
    PREFIX crm: <http://www.cidoc-crm.org/cidoc-crm/>
    PREFIX crmsci: <http://cidoc-crm.org/extensions/crmsci/>
    PREFIX crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>
    PREFIX edm: <http://www.europeana.eu/schemas/edm/>
    PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
    PREFIX time: <http://www.w3.org/2006/time#>
    PREFIX schema: <http://schema.org/>
    PREFIX ah: <https://purl.org/megalod/ms/ah/>
    PREFIX excav: <https://purl.org/megalod/ms/excavation/>
    PREFIX dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#>
    
    CONSTRUCT {
        ?s ?p ?o .
        ?related ?relP ?relO .
        ?encounter ?encP ?encO .
    }
    WHERE {
        GRAPH <$graphUri> {
            # Main item and its direct properties
            <$itemUriPattern> ?p ?o .
            BIND(<$itemUriPattern> AS ?s)
            
            # Get related resources (morphology, chipping, coordinates, etc.)
            OPTIONAL {
                <$itemUriPattern> ?linkProp ?related .
                ?related ?relP ?relO .
                FILTER(STRSTARTS(STR(?related), STR(<$itemUriPattern>)))
            }
            
            # Get encounter events that reference this item
            OPTIONAL {
                ?encounter crmsci:O19_encountered_object <$itemUriPattern> .
                ?encounter ?encP ?encO .
            }
            
            # Get context resources (location, square, context, svu)
            OPTIONAL {
                <$itemUriPattern> ?contextProp ?contextRes .
                ?contextRes ?ctxP ?ctxO .
                FILTER(?contextProp IN (excav:foundInLocation, excav:foundInSquare, excav:foundInContext, excav:foundInSVU))
                BIND(?contextRes AS ?s)
                BIND(?ctxP AS ?p)
                BIND(?ctxO AS ?o)
            }
        }
    }
    ";
    
    $rawTtlData = $this->executeConstructQuery($query);

    if ($rawTtlData) {
        $organizedTtl = $this->organizeAndFormatItemTtl($rawTtlData, $identifier, $itemSetId);
        
   
        return $organizedTtl;
    }
    
    return null;
}
/**
 * This method organizes and formats the raw TTL data for a specific item.
 * It groups statements by resource type and adds appropriate headers.
 * @param string $rawTtlData The raw TTL data as a string
 * @param string $identifier The identifier of the item
 * @param int $itemSetId The ID of the item set for which the TTL is being organized
 * @return string The organized TTL data
 */
private function organizeAndFormatItemTtl($rawTtlData, $identifier, $itemSetId)
{
    $subjects = $this->parseTtlIntoSubjects($rawTtlData);
    
    // Build organized TTL
    $organizedTtl = $this->getTtlPrefixes();
    $organizedTtl .= "\n# ========================================================================================\n";
    $organizedTtl .= "# ARCHAEOLOGICAL ITEM DATA - " . strtoupper($identifier) . "\n";
    $organizedTtl .= "# Downloaded from GraphDB on " . date('Y-m-d H:i:s') . "\n";
    $organizedTtl .= "# Item Set: $itemSetId | Item ID: $identifier\n";
    $organizedTtl .= "# Organized by resource type for better readability\n";
    $organizedTtl .= "# ========================================================================================\n\n";
    
    $sections = [
        'main_item' => [
            'title' => 'MAIN ARCHAEOLOGICAL ITEM',
            'pattern' => '/(ah:Arrowhead|excav:Item)/'
        ],
        'morphology' => [
            'title' => 'MORPHOLOGY',
            'pattern' => '/ah:Morphology/'
        ],
        'chipping' => [
            'title' => 'CHIPPING',
            'pattern' => '/ah:Chipping/'
        ],
        'typometry' => [
            'title' => 'TYPOMETRY VALUES',
            'pattern' => '/excav:TypometryValue/'
        ],
        'weights' => [
            'title' => 'WEIGHT VALUES',
            'pattern' => '/excav:Weight/'
        ],
        'coordinates' => [
            'title' => 'COORDINATES IN SQUARE',
            'pattern' => '/excav:Coordinates/'
        ],
        'gps' => [
            'title' => 'GPS COORDINATES',
            'pattern' => '/excav:GPSCoordinates/'
        ],
        'encounters' => [
            'title' => 'ENCOUNTER EVENTS',
            'pattern' => '/excav:EncounterEvent/'
        ],
        'excavation' => [
            'title' => 'EXCAVATION REFERENCE',
            'pattern' => '/excav:Excavation/'
        ],
        'location' => [
            'title' => 'LOCATION REFERENCE',
            'pattern' => '/excav:Location/'
        ],
        'squares' => [
            'title' => 'SQUARE REFERENCE',
            'pattern' => '/excav:Square/'
        ],
        'contexts' => [
            'title' => 'CONTEXT REFERENCE',
            'pattern' => '/excav:Context/'
        ],
        'svus' => [
            'title' => 'SVU REFERENCE',
            'pattern' => '/excav:StratigraphicVolumeUnit/'
        ],
        'timelines' => [
            'title' => 'TIMELINE REFERENCE',
            'pattern' => '/excav:TimeLine/'
        ],
        'instants' => [
            'title' => 'TIME INSTANT REFERENCE',
            'pattern' => '/excav:Instant/'
        ],
        'external' => [
            'title' => 'EXTERNAL REFERENCE DECLARATIONS',
            'pattern' => '/(dbo:district|dbo:parish|dbo:Country)/'
        ]
    ];
    
    // Process each section
    foreach ($sections as $sectionKey => $sectionInfo) {
        $sectionSubjects = $this->findSubjectsByPattern($subjects, $sectionInfo['pattern']);
        
        if (!empty($sectionSubjects)) {
            $organizedTtl .= "# =========== {$sectionInfo['title']} ===========\n\n";
            
            if ($sectionKey === 'main_item') {
                $mainItemUri = "http://localhost/megalod/$itemSetId/item/$identifier";
                if (isset($sectionSubjects["<$mainItemUri>"])) {
                    $organizedTtl .= $this->formatSubjectStatements("<$mainItemUri>", $sectionSubjects["<$mainItemUri>"]);
                    unset($sectionSubjects["<$mainItemUri>"]);
                    $organizedTtl .= "\n";
                }
            }
            
            foreach ($sectionSubjects as $subject => $statements) {
                $organizedTtl .= $this->formatSubjectStatements($subject, $statements);
                $organizedTtl .= "\n";
            }
            
            $organizedTtl .= "\n";
        }
    }

    
    
    return $organizedTtl;
}
/**
 * This method executes a SPARQL CONSTRUCT query against the GraphDB endpoint.
 * It returns the TTL data as a string, or null if the query fails.
 * @param string $query The SPARQL CONSTRUCT query to execute
 * @return string|null The TTL data or null on failure
 */
private function executeConstructQuery($query)
{
    try {
        $client = new \Laminas\Http\Client();
        $client->setUri($this->graphdbQueryEndpoint);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'text/turtle' 
        ]);
        $client->setRawBody($query);
        
        $response = $client->send();
        
        if ($response->isSuccess()) {
            $ttlData = $response->getBody();
            
            if (strpos($ttlData, '@prefix') === false) {
                $ttlData = $this->getTtlPrefixes() . "\n" . $ttlData;
            }
            
            return $ttlData;
        } else {
   
            return null;
        }
        
    } catch (\Exception $e) {
   
        return null;
    }
}
/**
 * This method sanitizes a filename by removing or replacing invalid characters.
 * It ensures the filename is safe for use in file systems.
 * @param string $filename The original filename
 * @return string The sanitized filename
 */
private function sanitizeFilename($filename)
{
    // Remove or replace invalid characters
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
    $filename = trim($filename, '_');
    
    if (empty($filename)) {
        $filename = 'download';
    }
    
    return $filename;
}




/**
 * This method queries the GraphDB using SPARQL and returns the results.
 * It sends a POST request to the GraphDB query endpoint with the provided query.
 * @param string $query The SPARQL query to execute
 * @return array The results of the query as an associative array
 */
private function querySparql($query)
{
    $client = new \Laminas\Http\Client();
    $client->setUri($this->graphdbQueryEndpoint);
    $client->setMethod('POST');
    $client->setHeaders([
        'Content-Type' => 'application/sparql-query',
        'Accept' => 'application/sparql-results+json'
    ]);
    $client->setRawBody($query);
    
    $response = $client->send();
    
    if ($response->isSuccess()) {
        $results = json_decode($response->getBody(), true);
        
        if (isset($results['results']['bindings'])) {
            return $results['results']['bindings'];
        }
    }
    
    return [];
}


/**
 * This method generates the TTL for an arrowhead resource with original URIs.
 * It uses the original excavation context URIs to construct the arrowhead URI.
 * @param mixed $resource The resource for which to generate the TTL
 * @return string The generated TTL string
 */
private function generateArrowheadTtlWithOriginalUris($resource)
{
    $values = $resource->values();
    
    // Extract the original normalized URI from the context references
    $originalBaseUri = $this->extractOriginalBaseUri($values, $resource);
    if ($originalBaseUri == null) {
   
        return "# No valid excavation context found for resource: " . $resource->id() . "\n";
    }
    $identifier = $this->extractIdentifierFromResource($resource);
    
    // Use the original normalized URI structure
    $arrowheadUri = "$originalBaseUri/item/$identifier";
    
    // Start building TTL with single resource comment
    $ttl = "# Resource: " . $resource->displayTitle() . "\n\n";
    
    // Main arrowhead declaration
    $ttl .= "<$arrowheadUri> a excav:Item, ah:Arrowhead ;\n";
    
    // Add identifier
    if ($identifier) {
        $ttl .= "    dct:identifier \"$identifier\"^^xsd:literal ;\n";
    }
    
    // Add basic properties
    $ttl .= $this->processArrowheadCorePropertiesWithOriginalUris($values, $arrowheadUri, $originalBaseUri);
    
    // Add measurement references
    $ttl .= $this->addMeasurementReferences($values, $arrowheadUri, $identifier);
    
    // Add morphology reference
    if ($this->hasMorphologyData($values)) {
        $ttl .= "    ah:hasMorphology <$arrowheadUri/morphology/$identifier-morphology> ;\n";
    }
    
    // Add chipping reference  
    if ($this->hasChippingData($values)) {
        $ttl .= "    ah:hasChipping <$arrowheadUri/chipping/$identifier-chipping> ;\n";
    }
    
    // Add coordinates reference
    if ($this->hasCoordinatesData($values)) {
        $ttl .= "    excav:hasCoordinatesInSquare <$arrowheadUri/coordinates/$identifier-coordinates> ;\n";
    }
    
    // Add GPS coordinates reference
    if ($this->hasGpsData($values)) {
        $excavationId = $this->extractExcavationId($values);
        $ttl .= "    excav:hasGPSCoordinates <$originalBaseUri/excavation/$excavationId/gps/$identifier-gps> ;\n";
    }
    
    // Close main resource (remove trailing semicolon and add period)
    $ttl = rtrim($ttl, " ;\n") . " .\n\n";
    
    // Now add all the separate objects in order
    $ttl .= $this->processMeasurementsWithOriginalUris($values, $arrowheadUri, $identifier);
    $ttl .= $this->processMorphologyWithOriginalUris($values, $arrowheadUri, $identifier);
    $ttl .= $this->processChippingWithOriginalUris($values, $arrowheadUri, $identifier);
    $ttl .= $this->processCoordinatesWithOriginalUris($values, $arrowheadUri, $identifier);
    $ttl .= $this->processGPSWithOriginalUris($values, $originalBaseUri, $identifier);
    
    return $ttl;
}

/**
 * This method adds measurement references to the TTL string.
 * It checks for various measurement values and constructs the appropriate URIs.
 * @param array $values The values from the resource
 * @param string $arrowheadUri The base URI for the arrowhead
 * @param string $identifier The identifier of the resource
 * @return string The TTL string with measurement references
 */
private function addMeasurementReferences($values, $arrowheadUri, $identifier)
{
    $ttl = "";
    
    $measurements = [
        'Height' => ['height', 'schema:height'],
        'Width' => ['width', 'schema:width'],
        'Weight' => ['weight', 'schema:weight'], 
        'Thickness' => ['depth', 'schema:depth'],
        'Body Length' => ['bodylength', 'ah:hasBodyLength'],
        'Base Length' => ['baselength', 'ah:hasBaseLength']
    ];
    
    foreach ($measurements as $label => $config) {
        $suffix = $config[0];
        $property = $config[1];
        
        if (isset($values[$label]) && !empty($values[$label]['values'])) {
            if ($property === 'schema:weight') {
                $ttl .= "    schema:weight <$arrowheadUri/weight/$identifier-weight> ;\n";
            } elseif (strpos($property, 'ah:') === 0) {
                $ttl .= "    $property <$arrowheadUri/$suffix/$identifier-$suffix> ;\n";
            } else {
                $propertyName = str_replace('schema:', '', $property);
                $ttl .= "    schema:$propertyName <$arrowheadUri/typometry/$identifier-$suffix> ;\n";
            }
        }
    }
    
    return $ttl;
}

/**
 * This method checks if the values contain morphology data.
 * It looks for specific properties that indicate morphology information.
 * @param array $values The values from the resource
 * @return bool True if morphology data is present, false otherwise
 */
private function hasMorphologyData($values)
{
    return isset($values['ah:point']) || isset($values['ah:body']) || isset($values['ah:base']);
}
/**
 * This method processes the chipping data and generates the TTL string.
 * @param mixed $values
 * @return bool
 */
private function hasChippingData($values)
{
    $chippingProperties = ['ah:chippingMode', 'ah:chippingAmplitude', 'ah:chippingDirection', 
                          'ah:chippingOrientation', 'ah:chippingDelineation', 'ah:chippingLocationSide',
                          'ah:chippingLocationTransversal', 'ah:chippingShape'];
    
    foreach ($chippingProperties as $prop) {
        if (isset($values[$prop])) {
            return true;
        }
    }
    return false;
}

/**
 * This method checks if the values contain coordinates data.
 * @param mixed $values
 * @return bool
 */
private function hasGpsData($values)
{
    return isset($values['excavation:hasGPSCoordinates']);
}
/**
 * This method extracts the excavation ID from the values.
 * It looks for the excavation context reference and extracts the ID from the URI.
 * @param mixed $values
 * @param mixed $resource
 * @return string|null The excavation ID or null if not found
 */
private function extractOriginalBaseUri($values, $resource)
{
    $contextProperties = [
        'excavation:foundInLocation',
        'excavation:foundInSquare', 
        'excavation:foundInContext',
        'excavation:foundInSVU'
    ];
    
    foreach ($contextProperties as $property) {
        if (isset($values[$property])) {
            foreach ($values[$property]['values'] as $value) {
                if ($value->uri()) {
                    $uri = $value->uri();
                    if (preg_match('/^(https:\/\/purl\.org\/megalod\/\d+)\/excavation\/[^\/]+\//', $uri, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }
    }
    
    $itemSets = $resource->itemSets();
    if (!empty($itemSets)) {
        $itemSetId = $itemSets[0]->id();
        return "http://localhost/megalod/$itemSetId";
    }
    
    return null;
}

/**
 * This method extracts the identifier from the resource.
 * It looks for the dcterms:identifier property and returns its value.
 * If not found, it generates a default identifier based on the resource ID.
 * @param mixed $resource The resource from which to extract the identifier
 * @return string The extracted or generated identifier
 */
private function extractIdentifierFromResource($resource)
{
    $values = $resource->values();
    
    if (isset($values['dcterms:identifier'])) {
        foreach ($values['dcterms:identifier']['values'] as $value) {
            return $value->value();
        }
    }
    
    return 'item-' . $resource->id();
}

/**
 * This method processes the core properties of an arrowhead resource
 * and generates the TTL string using the original URIs.
 * @param mixed $values
 * @param mixed $arrowheadUri
 * @param mixed $originalBaseUri
 * @return string
 */
private function processArrowheadCorePropertiesWithOriginalUris($values, $arrowheadUri, $originalBaseUri)
{
    $ttl = "";
    
    // Process description
    if (isset($values['dcterms:description'])) {
        foreach ($values['dcterms:description']['values'] as $value) {
            $ttl .= "    dbo:Annotation \"" . $this->escapeTtlString($value->value()) . "\"^^xsd:literal ;\n";
        }
    }
    
    // Process condition state as boolean
    if (isset($values['crm:P44_has_condition'])) {
        foreach ($values['crm:P44_has_condition']['values'] as $value) {
            $boolValue = (strtolower($value->value()) === 'true') ? 'true' : 'false';
            $ttl .= "    crm:E3_Condition_State $boolValue ;\n";
        }
    }
    
    // Process type as boolean
    if (isset($values['crm:P2_has_type'])) {
        foreach ($values['crm:P2_has_type']['values'] as $value) {
            $boolValue = (strtolower($value->value()) === 'true') ? 'true' : 'false';
            $ttl .= "    crm:E55_Type $boolValue ;\n";
        }
    }
    
    // Process material with proper URI
    if (isset($values['schema:material'])) {
        foreach ($values['schema:material']['values'] as $value) {
            $materialUri = "http://vocab.getty.edu/page/aat/" . $value->value();
            $ttl .= "    crm:E57_Material <$materialUri> ;\n";
        }
    }
    
    // Process shape with controlled vocabulary URI (preserve original)
    if (isset($values['ah:shape'])) {
        foreach ($values['ah:shape']['values'] as $value) {
            $shapeValue = strtolower($value->value());
            $ttl .= "    ah:shape <https://purl.org/megalod/kos/ah-shape/$shapeValue> ;\n";
        }
    }
    
    if (isset($values['ah:variant'])) {
        foreach ($values['ah:variant']['values'] as $value) {
            $variantValue = strtolower($value->value());
            $ttl .= "    ah:variant <https://purl.org/megalod/kos/ah-variant/$variantValue> ;\n";
        }
    }
    
    if (isset($values['excavation:elongationIndex'])) {
        foreach ($values['excavation:elongationIndex']['values'] as $value) {
            $ttl .= "    excav:elongationIndex <https://purl.org/megalod/kos/MegaLOD-IndexElongation/" . $value->value() . "> ;\n";
        }
    }
    
    if (isset($values['excavation:thicknessIndex'])) {
        foreach ($values['excavation:thicknessIndex']['values'] as $value) {
            $ttl .= "    excav:thicknessIndex <https://purl.org/megalod/kos/MegaLOD-IndexThickness/" . $value->value() . "> ;\n";
        }
    }
    
    $contextProperties = [
        'excavation:foundInLocation' => 'excav:foundInLocation',
        'excavation:foundInSquare' => 'excav:foundInSquare', 
        'excavation:foundInContext' => 'excav:foundInContext',
        'excavation:foundInSVU' => 'excav:foundInSVU'
    ];
    
    foreach ($contextProperties as $omekaProperty => $ttlProperty) {
        if (isset($values[$omekaProperty])) {
            foreach ($values[$omekaProperty]['values'] as $value) {
                if ($value->uri()) {
                    $ttl .= "    $ttlProperty <" . $value->uri() . "> ;\n";
                }
            }
        }
    }

    if (isset($values['district'])) {
        foreach ($values['district']['values'] as $value) {
            $districtName = $value->value();
            $districtSlug = str_replace(' ', '_', $districtName);
            $districtUri = "http://dbpedia.org/resource/$districtSlug";
            $ttl .= "    dbo:district <$districtUri> ;\n";
            $entitiesToDeclare['district'] = [
                'uri' => $districtUri,
                'name' => $districtName
            ];
        }
    }
    
    if (isset($values['parish'])) {
        foreach ($values['parish']['values'] as $value) {
            $parishName = $value->value();
            $parishSlug = str_replace(' ', '_', $parishName);
            $parishUri = "http://dbpedia.org/resource/$parishSlug";
            $ttl .= "    dbo:parish <$parishUri> ;\n";
            $entitiesToDeclare['parish'] = [
                'uri' => $parishUri,
                'name' => $parishName
            ];
        }
    }
    
    if (isset($values['Country'])) {
        foreach ($values['Country']['values'] as $value) {
            $countryName = $value->value();
            $countrySlug = str_replace(' ', '_', $countryName);
            $countryUri = "http://dbpedia.org/resource/$countrySlug";
            $ttl .= "    dbo:Country <$countryUri> ;\n";

        }
    }
    
    if (isset($values['dcterms:date'])) {
        foreach ($values['dcterms:date']['values'] as $value) {
            $ttl .= "    dct:date \"" . $value->value() . "\"^^xsd:literal ;\n";
        }
    }
    
    if (isset($values['dcterms:hasFormat'])) {
        foreach ($values['dcterms:hasFormat']['values'] as $value) {
            if ($value->uri()) {
                $ttl .= "    edm:Webresource <" . $value->uri() . "> ;\n";
            }
        }
    }

    if (!empty($entitiesToDeclare)) {
        $ttl .= "\n# Type declarations for referenced resources\n";
        
        if (isset($entitiesToDeclare['district'])) {
            $ttl .= "<{$entitiesToDeclare['district']['uri']}> a dbo:District .\n";
        }
        
        if (isset($entitiesToDeclare['parish'])) {
            $ttl .= "<{$entitiesToDeclare['parish']['uri']}> a dbo:Parish .\n";
        }
        

        
        $ttl .= "\n";
    }
    
    return $ttl;
}

/**
 * This method processes the measurements with original URIs.
 * @param mixed $values
 * @param mixed $arrowheadUri
 * @param mixed $identifier
 * @return string
 */
private function processMeasurementsWithOriginalUris($values, $arrowheadUri, $identifier)
{
    $ttl = "";
    $hasAnyMeasurements = false;
    
    $measurements = [
        'Height' => ['height', 'schema:height', null],
        'Width' => ['width', 'schema:width', null],
        'Weight' => ['weight', 'schema:weight', null], 
        'Thickness' => ['depth', 'schema:depth', null],
        'Body Length' => ['bodylength', 'ah:hasBodyLength', null],
        'Base Length' => ['baselength', 'ah:hasBaseLength', null],
    ];
    
    $measurementObjects = "";
    
    foreach ($measurements as $label => $config) {
        $suffix = $config[0];
        $property = $config[1];
        $defaultUnit = $config[2];
        
        if (isset($values[$label]) && !empty($values[$label]['values'])) {
            foreach ($values[$label]['values'] as $value) {
                $measurementValue = $value->value();
                $hasAnyMeasurements = true;
                
                if (preg_match('/^([0-9.]+)\s*([A-Z]+)?/', $measurementValue, $matches)) {
                    $numericValue = $matches[1];
                    $unit = isset($matches[2]) && !empty($matches[2]) ? $matches[2] : $defaultUnit;
                    
                    if ($property === 'schema:weight') {
                        $measurementUri = "$arrowheadUri/weight/$identifier-weight";
                        $measurementObjects .= "<$measurementUri> a excav:Weight ;\n";
                        $measurementObjects .= "    schema:value \"$numericValue\"^^xsd:decimal ;\n";
                        $measurementObjects .= "    schema:UnitCode <http://qudt.org/vocab/unit/$unit> .\n\n";
                    } elseif (strpos($property, 'ah:') === 0) {
                        $propName = str_replace('ah:has', '', $property);
                        $measurementUri = "$arrowheadUri/$suffix/$identifier-$suffix";
                        $measurementObjects .= "<$measurementUri> a excav:TypometryValue ;\n";
                        $measurementObjects .= "    schema:value \"$numericValue\"^^xsd:decimal ;\n";
                        $measurementObjects .= "    schema:UnitCode <http://qudt.org/vocab/unit/$unit> .\n\n";
                    } else {
                        $propName = str_replace('schema:', '', $property);
                        $measurementUri = "$arrowheadUri/typometry/$identifier-$propName";
                        $measurementObjects .= "<$measurementUri> a excav:TypometryValue ;\n";
                        $measurementObjects .= "    schema:value \"$numericValue\"^^xsd:decimal ;\n";
                        $measurementObjects .= "    schema:UnitCode <http://qudt.org/vocab/unit/$unit> .\n\n";
                    }
                }
            }
        }
    }
    
    if ($hasAnyMeasurements && $measurementObjects) {
        $ttl .= "# =========== TYPOMETRY VALUES ===========\n\n";
        $ttl .= $measurementObjects;
    }
    
    return $ttl;
}



/**
 * This method processes the morphology data and generates the TTL string.
 * It uses original URIs for morphology properties.
 * @param mixed $values
 * @param mixed $arrowheadUri
 * @param mixed $identifier
 * @return string
 */
private function processMorphologyWithOriginalUris($values, $arrowheadUri, $identifier)
{
    $ttl = "";
    $morphologyUri = "$arrowheadUri/morphology/$identifier-morphology";
    
    $morphologyProperties = ['ah:point', 'ah:body', 'ah:base', 'Point Definition (Sharp/Fractured)', 
                          'Body Symmetry (Symmetrical/Non-symmetrical)', 'Base Type'];
    
    $hasMorphologyData = false;
    foreach ($morphologyProperties as $prop) {
        if (isset($values[$prop])) {
            $hasMorphologyData = true;
            break;
        }
    }
    
    if ($hasMorphologyData) {
        $ttl .= "# =========== MORPHOLOGY ===========\n\n";
        $ttl .= "<$morphologyUri> a ah:Morphology ;\n";
        
        $morphologyStatements = [];
        
        if (isset($values['ah:point'])) {
            foreach ($values['ah:point']['values'] as $value) {
                $boolValue = (strtolower($value->value()) === 'true' || strtolower($value->value()) === 'sharp') ? 'true' : 'false';
                $morphologyStatements[] = "    ah:point $boolValue";
            }
        } else if (isset($values['Point Definition (Sharp/Fractured)'])) {
            foreach ($values['Point Definition (Sharp/Fractured)']['values'] as $value) {
                $boolValue = (strtolower($value->value()) === 'true' || strtolower($value->value()) === 'sharp') ? 'true' : 'false';
                $morphologyStatements[] = "    ah:point $boolValue";
            }
        }
        
        if (isset($values['ah:body'])) {
            foreach ($values['ah:body']['values'] as $value) {
                $boolValue = (strtolower($value->value()) === 'true' || strtolower($value->value()) === 'symmetrical') ? 'true' : 'false';
                $morphologyStatements[] = "    ah:body $boolValue";
            }
        } else if (isset($values['Body Symmetry (Symmetrical/Non-symmetrical)'])) {
            foreach ($values['Body Symmetry (Symmetrical/Non-symmetrical)']['values'] as $value) {
                $boolValue = (strtolower($value->value()) === 'true' || strtolower($value->value()) === 'symmetrical') ? 'true' : 'false';
                $morphologyStatements[] = "    ah:body $boolValue";
            }
        }
        
        if (isset($values['ah:base'])) {
            foreach ($values['ah:base']['values'] as $value) {
                $baseValue = strtolower($value->value());
                $baseValue = preg_replace('/\s+/', '-', $baseValue); 
                $morphologyStatements[] = "    ah:base <https://purl.org/megalod/kos/ah-base/$baseValue>";
            }
        } else if (isset($values['Base Type'])) {
            foreach ($values['Base Type']['values'] as $value) {
                $baseValue = strtolower($value->value());
                $baseValue = preg_replace('/\s+/', '-', $baseValue); 
                $morphologyStatements[] = "    ah:base <https://purl.org/megalod/kos/ah-base/$baseValue>";
            }
        }
        
        if (!empty($morphologyStatements)) {
            $ttl .= implode(" ;\n", $morphologyStatements) . " .\n\n";
        } else {
            $ttl .= "    .\n\n"; 
        }
    }
    
    return $ttl;
}

/**
 * This method processes the chipping data and generates the TTL string.
 * It uses original URIs for chipping properties.
 * It checks for the presence of chipping data and constructs the appropriate URIs.
 * If chipping data is present, it generates a chipping object with the relevant properties.
 * @param mixed $values
 * @param mixed $arrowheadUri
 * @param mixed $identifier
 * @return string
 */
private function processChippingWithOriginalUris($values, $arrowheadUri, $identifier)
{
    $ttl = "";
    $chippingUri = "$arrowheadUri/chipping/$identifier-chipping";
    
    $chippingProperties = ['ah:chippingMode', 'ah:chippingAmplitude', 'ah:chippingDirection', 
                          'ah:chippingOrientation', 'ah:chippingDelineation', 'ah:chippingLocationSide',
                          'ah:chippingLocationTransversal', 'ah:chippingShape'];
    
    $hasChippingData = false;
    foreach ($chippingProperties as $prop) {
        if (isset($values[$prop])) {
            $hasChippingData = true;
            break;
        }
    }
    
    if ($hasChippingData) {
        
        $ttl .= "\n# =========== CHIPPING ===========\n\n";
        $ttl .= "<$chippingUri> a ah:Chipping ;\n";
        
        if (isset($values['ah:chippingMode'])) {
            foreach ($values['ah:chippingMode']['values'] as $value) {
                $modeValue = strtolower($value->value());
                $ttl .= "    ah:chippingMode <https://purl.org/megalod/kos/ah-chippingMode/$modeValue> ;\n";
            }
        }
        
        if (isset($values['ah:chippingAmplitude'])) {
            foreach ($values['ah:chippingAmplitude']['values'] as $value) {
                $boolValue = (strtolower($value->value()) === 'true') ? 'true' : 'false';
                $ttl .= "    ah:chippingAmplitude $boolValue ;\n";
            }
        }
        
        if (isset($values['ah:chippingDirection'])) {
            foreach ($values['ah:chippingDirection']['values'] as $value) {
                $directionValue = strtolower($value->value());
                $ttl .= "    ah:chippingDirection <https://purl.org/megalod/kos/ah-chippingDirection/$directionValue> ;\n";
            }
        }
        
        if (isset($values['ah:chippingOrientation'])) {
            foreach ($values['ah:chippingOrientation']['values'] as $value) {
                $boolValue = (strtolower($value->value()) === 'true') ? 'true' : 'false';
                $ttl .= "    ah:chippingOrientation $boolValue ;\n";
            }
        }
        
        if (isset($values['ah:chippingDelineation'])) {
            foreach ($values['ah:chippingDelineation']['values'] as $value) {
                $delineationValue = strtolower($value->value());
                $ttl .= "    ah:chippingDelineation <https://purl.org/megalod/kos/ah-chippingDelineation/$delineationValue> ;\n";
            }
        }
        
        if (isset($values['ah:chippingLocationSide'])) {
            foreach ($values['ah:chippingLocationSide']['values'] as $value) {
                $locationValue = strtolower($value->value());
                $ttl .= "    ah:chippingLocationSide <https://purl.org/megalod/kos/ah-chippingLocation/$locationValue> ;\n";
            }
        }
        
        if (isset($values['ah:chippingLocationTransversal'])) {
            foreach ($values['ah:chippingLocationTransversal']['values'] as $value) {
                $locationValue = strtolower($value->value());
                $ttl .= "    ah:chippingLocationTransversal <https://purl.org/megalod/kos/ah-chippingLocation/$locationValue> ;\n";
            }
        }
        
        if (isset($values['ah:chippingShape'])) {
            foreach ($values['ah:chippingShape']['values'] as $value) {
                $shapeValue = strtolower($value->value());
                $ttl .= "    ah:chippingShape <https://purl.org/megalod/kos/ah-chippingShape/$shapeValue> ;\n";
            }
        }
        
        $ttl = rtrim($ttl, ";\n") . " .\n\n";
    }
    
    return $ttl;
}

/**
 * This method checks if the values contain coordinates data.
 * It looks for specific properties that indicate coordinates information.
 * @param mixed $values
 * @return bool True if coordinates data is present, false otherwise
 */
private function hasCoordinatesData($values)
{
    return isset($values['Coordinates']) || isset($values['excavation:hasCoordinatesInSquare']);
}


/**
 * This method processes the coordinates data and generates the TTL string.
 * It uses original URIs for coordinates properties.
 * It checks for the presence of coordinates data and constructs the appropriate URIs.
 * If coordinates data is present, it generates a coordinates object with the relevant properties.
 * @param mixed $values
 * @param mixed $arrowheadUri
 * @param mixed $identifier
 * @return string
 */
private function processCoordinatesWithOriginalUris($values, $arrowheadUri, $identifier)
{
    $ttl = "";
    $coordinateProperties = ['Coordinates', 'excavation:hasCoordinatesInSquare'];
    $axes = ['x', 'y', 'z'];
    $found = ['x' => null, 'y' => null, 'z' => null];

    foreach ($coordinateProperties as $property) {
        if (isset($values[$property])) {
            foreach ($values[$property]['values'] as $value) {
                $coordString = $value->value();
                if (preg_match_all('/([XYZ]):\s*([0-9.]+)/', $coordString, $matches, PREG_SET_ORDER)) {
                    $coordinatesUri = "$arrowheadUri/coordinates/$identifier-coordinates";
                    $ttl .= "\n# =========== COORDINATES IN SQUARE ===========\n\n";
                    $ttl .= "<$coordinatesUri> a excav:Coordinates ;\n";
                    foreach ($matches as $match) {
                        $axis = strtolower($match[1]);
                        $found[$axis] = $match[2];
                        $typometryUri = "$arrowheadUri/typometry/$identifier-$axis";
                        if ($axis === 'x') {
                            $ttl .= "    geo:long <$typometryUri> ;\n";
                        } elseif ($axis === 'y') {
                            $ttl .= "    geo:lat <$typometryUri> ;\n";
                        } else {
                            $ttl .= "    schema:depth <$typometryUri> ;\n";
                        }
                    }
                    $ttl = rtrim($ttl, ";\n") . " .\n\n";
                    // declare axis (X, Y, Z)
                    foreach ($axes as $axis) {
                        $typometryUri = "$arrowheadUri/typometry/$identifier-$axis";
                        $ttl .= "<$typometryUri> a excav:TypometryValue ;\n";
                        if ($found[$axis] !== null) {
                            $ttl .= "    schema:value \"{$found[$axis]}\"^^xsd:decimal ;\n";
                        } else {
                            $ttl .= "    schema:value \"\"^^xsd:decimal ;\n";
                        }
                        $ttl .= "    schema:UnitCode <http://qudt.org/vocab/unit/CMT> .\n\n";
                    }
                    break 2;
                }
            }
        }
    }
    return $ttl;
}

/**
 * This method processes the GPS coordinates data and generates the TTL string.
 * It uses original URIs for GPS properties.
 * It checks for the presence of GPS coordinates data and constructs the appropriate URIs.
 * If GPS coordinates data is present, it generates a GPS object with the relevant properties.
 * @param mixed $values
 * @param mixed $originalBaseUri
 * @param mixed $identifier
 * @return string
 */
private function processGPSWithOriginalUris($values, $originalBaseUri, $identifier)
{
    $ttl = "";
    
    if (isset($values['excavation:hasGPSCoordinates'])) {
        foreach ($values['excavation:hasGPSCoordinates']['values'] as $value) {
            $gpsString = $value->value();
            
            // Parse GPS string "lat: _, Long:_"
            if (preg_match('/Lat:\s*([0-9.-]+),\s*Long:\s*([0-9.-]+)/', $gpsString, $matches)) {
                $lat = $matches[1];
                $long = $matches[2];
                
                $excavationId = $this->extractExcavationId($values);
                $gpsUri = "$originalBaseUri/excavation/$excavationId/gps/$identifier-gps";
                                
                $ttl .= "\n# =========== GPS COORDINATES ===========\n\n";
                $ttl .= "<$gpsUri> a excav:GPSCoordinates ;\n";
                $ttl .= "    geo:lat \"$lat\"^^xsd:decimal ;\n";
                $ttl .= "    geo:long \"$long\"^^xsd:decimal .\n\n";
            }
        }
    }
    
    return $ttl;
}

/**
 * This method extracts the excavation ID from the values.
 * It looks for the excavation context reference and extracts the ID from the URI.
 * @param mixed $values The values from the resource
 * @return string The excavation ID or 'unknown' if not found
 */
private function extractExcavationId($values)
{
    $contextProperties = [
        'excavation:foundInLocation',
        'excavation:foundInSquare', 
        'excavation:foundInContext',
        'excavation:foundInSVU'
    ];
    
    foreach ($contextProperties as $property) {
        if (isset($values[$property])) {
            foreach ($values[$property]['values'] as $value) {
                if ($value->uri()) {
                    $uri = $value->uri();
                    if (preg_match('/\/excavation\/([^\/]+)\//', $uri, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }
    }
    
    return 'unknown';
}






/**
 * This method escapes special characters in a string for use in TTL format.
 * It replaces quotes, backslashes, and control characters with their escaped versions.
 * @param string $string The string to escape
 * @return string The escaped string
 */
private function escapeTtlString($string)
{
    return str_replace(
        ['"', '\\', "\n", "\r", "\t"],
        ['\"', '\\\\', '\\n', '\\r', '\\t'],
        $string
    );
}




}