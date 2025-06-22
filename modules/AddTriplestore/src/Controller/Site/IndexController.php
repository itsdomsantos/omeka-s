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


/**
 * Handles pre-dispatch validation and access control logic
 * 
 * Calls the parent preDispatch method if it exists and checks if the current user is trying to access admin areas and prevents
 *    site-only users from accessing unauthorized sections.
 * @param \Laminas\Mvc\MvcEvent $e The MVC event
 * @return mixed|void Response object in case of redirect, void otherwise
 */
public function preDispatch(\Laminas\Mvc\MvcEvent $e)
{
    // Calling parent preDispatch if it exists
    if (method_exists(get_parent_class(), 'preDispatch')) {
        parent::preDispatch($e);
    }
    
    $this->preventAdminAccess($e);
}


/**
 * Prevents unauthorized access to admin areas
 * 
 * Checks if the current user is trying to access administrative sections and redirects site-only users 
 *    back to their allowed site. This helps maintain proper access control by ensuring users only
 *    access areas they have permission for.
 *
 * @param \Laminas\Mvc\MvcEvent $e The MVC event object containing request and response information
 * @return mixed|void Response object if redirect needed, void otherwise
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
        
        // If user is a site-only user (guest role), redirect them away from admin
        if ($user->getRole() === 'guest') {
            $this->messenger()->addError('Access denied. You do not have permission to access administrative areas.');
            
            // Redirect to their allowed site
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
 * Shows a user dashboard for site-only users
 * 
 * Displays a custom dashboard for users with site-only access. Redirects admin users to the main admin 
 * dashboard while showing site-specific content and user information for site-only users. This helps provide 
 * appropriate access levels and relevant information based on user roles.
 * 
 * @return \Laminas\View\Model\ViewModel|mixed Returns ViewModel for site users, redirects admins to admin dashboard
 */
public function dashboardAction()
{
    $redirect = $this->requireLogin();
    if ($redirect) return $redirect;
    
    $user = $this->identity();
    
    // If this is an admin user, redirect to actual admin dashboard
    if ($this->userHasAdminAccess($user)) {
        return $this->redirect()->toUrl('/admin');
    }
    
    // Show site-only user dashboard
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
 * Log out a user from the site
 *
 * Clears the user identity, site-specific user session data,
 * and destroys the session. Then redirects back to the site homepage
 * with a success message.
 *
 * @return \Laminas\Http\Response
 */
/**
 * Update your logout to clear site-only session data
 */
public function logoutAction()
{
    $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
    $auth->clearIdentity();
    
    // Clear site-only user session data
    $session = new Container('site_user');
    $session->getManager()->getStorage()->clear();
    
    $sessionManager = Container::getDefaultManager();
    $sessionManager->destroy();
    
    $this->messenger()->addSuccess('Successfully logged out');
    return $this->redirect()->toRoute('site', ['site-slug' => $this->currentSite()->slug()]);
}

/**
 * Get the service locator (service manager) for dependency management.
 *
 * This method retrieves the application service manager from the MVC event,
 * providing access to registered services within the Omeka S application.
 *
 * @return \Laminas\ServiceManager\ServiceManager The service manager instance
 */
private function getServiceLocator()
{
    // Get the application service manager from the MVC event
    $serviceManager = $this->getEvent()->getApplication()->getServiceManager();
    return $serviceManager;
}

/**
 * Action to provide SPARQL query interface via GraphDB.
 * 
 * This method sets up auto-login to a GraphDB instance with read-only credentials.
 * It prepares a view that will auto-submit to GraphDB, passing along the predefined 
 * read-only authentication credentials. The user will be redirected to the GraphDB
 * SPARQL interface without needing to manually enter login information.
 * 
 * @return \Laminas\View\Model\ViewModel The view model containing GraphDB connection parameters
 */
public function sparqlAction()
{
    // Auto-login to GraphDB with read-only credentials
    $graphdbUrl = 'http://localhost:7200/'; //
    
    // Create a form that auto-submits to GraphDB with read-only credentials
    $view = new ViewModel();
    $view->setVariable('graphdbUrl', $graphdbUrl);
    $view->setVariable('username', 'read_only_user');
    $view->setVariable('password', ''); // No password for read_only_user
    $view->setTemplate('add-triplestore/site/index/sparql-redirect');
    
    return $view;
}



/**
 * Handle user signup for the site.
 * 
 * This action allows visitors to create a site-only user account.
 * If the user is already logged in, they will be redirected to the site's homepage.
 * The method processes the signup form submission, validates the input data,
 * ensures passwords match, and creates a new user account if all validations pass.
 *
 * @return \Laminas\View\Model\ViewModel|\Laminas\Http\Response
 *         Returns either a ViewModel with the signup form for GET requests
 *         or invalid POST submissions, or a redirect response on successful
 *         signup or if user is already logged in.
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
                error_log('Error creating user: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/user-creation.log');
                error_log('Stack trace: ' . $e->getTraceAsString(), 3, OMEKA_PATH . '/logs/user-creation.log');
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
 * Creates a new user with site-only access privileges
 *
 * This method creates a user with the 'guest' role that has no admin access
 * but can access the current site. It performs the following steps:
 * 1. Checks if a user with the provided email already exists
 * 2. Hashes the password using Omeka's password hashing method
 * 3. Inserts the new user with 'guest' role and active status
 * 4. Adds the user to the current site with viewer permissions
 *
 * @param array $userData Array containing user information with the following keys:
 *                       - email: User's email address
 *                       - name: User's display name
 *                       - password: User's plain text password (will be hashed)
 * 
 * @return array Response array with:
 *               - success: boolean indicating if the operation succeeded
 *               - error: string error message (only when success is false)
 *
 * @throws \Exception May throw exceptions during database operations or user validation
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
        
        // Hash password using Omeka's method to ensure compatibility with login
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
            'guest', // This is key - 'guest' role has no admin access
            1, // is_active
            $hashedPassword,
            date('Y-m-d H:i:s')
        ]);
        
        if ($result) {
            $userId = $connection->lastInsertId();
            
            // Add user to the current site with viewer permissions
            $this->addUserToSite($userId, $this->currentSite()->id());
            
            error_log('Site-only user created successfully: ' . $userData['email'], 3, OMEKA_PATH . '/logs/user-creation.log');
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'Failed to create user account'];
        }
        
    } catch (\Exception $e) {
        error_log('Error creating site-only user: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/user-creation.log');
        error_log('Stack trace: ' . $e->getTraceAsString(), 3, OMEKA_PATH . '/logs/user-creation.log');
        
        if (strpos($e->getMessage(), 'email') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
            return ['success' => false, 'error' => 'A user with this email already exists'];
        } else {
            return ['success' => false, 'error' => 'Failed to create account: ' . $e->getMessage()];
        }
    }
}


/**
 * Grant site access permissions to a user
 *
 * Adds a user to a specific site with 'viewer' role by inserting a record
 * into the site_permission table. This method handles database operations
 * and logs the results.
 *
 * @param int $userId The ID of the user to add
 * @param int $siteId The ID of the site to give access to
 * @return void
 * 
 * @throws \Exception Catches but does not propagate exceptions that occur during DB operations
 */
private function addUserToSite($userId, $siteId)
{
    try {
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        
        // Add user to site_permission table with 'viewer' role
        $insertSql = "INSERT INTO site_permission (site_id, user_id, role) VALUES (?, ?, ?)";
        $stmt = $connection->prepare($insertSql);
        $stmt->execute([$siteId, $userId, 'viewer']);
        
        error_log("Added user $userId to site $siteId with viewer permissions", 3, OMEKA_PATH . '/logs/user-creation.log');
        
    } catch (\Exception $e) {
        error_log('Error adding user to site: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/user-creation.log');
        // Don't fail the whole process if this fails
    }
}


/**
 * Determines if a user has administrative access.
 *
 * This function checks if the provided user has a role that grants administrative
 * access to the system. Users with roles like 'global_admin', 'site_admin', etc.
 * are considered to have admin access.
 *
 * @param \Omeka\Entity\User|null $user The user entity to check for admin access
 * @return bool True if the user has admin access, false otherwise
 */
private function userHasAdminAccess($user)
{
    if (!$user) {
        return false;
    }
    
    $role = $user->getRole();
    
    // Admin roles that can access the dashboard
    $adminRoles = ['global_admin', 'site_admin', 'editor', 'reviewer', 'author'];
    
    return in_array($role, $adminRoles);
}


/**
 * My Data Action Controller
 * 
 * Displays user-specific data in the front-end site context, specifically focusing
 * on arrowhead items owned by or associated with the authenticated user.
 * 
 * This controller method:
 * 1. Enforces user authentication (redirects to login if not authenticated)
 * 2. Redirects admins to the admin dashboard
 * 3. Retrieves items and item sets owned by the current user through multiple strategies:
 *    - Direct owner_id API search
 *    - Fallback manual filtering if API search yields no results
 *    - Including items from item sets owned by the user
 * 4. Filters the results to only include arrowhead-related items using multiple identification methods:
 *    - Resource class checking
 *    - Arrowhead-specific property existence
 *    - Title pattern matching
 *    - Exclusion of known non-arrowhead patterns
 * 5. Logs detailed debug information throughout the process
 * 6. Renders the my-data template with user's items and related information
 * 
 * @return \Laminas\View\Model\ViewModel|Response The my-data view with user items or a redirect response
 */
public function myDataAction()
{
    $redirect = $this->requireLogin();
    if ($redirect) return $redirect;
    
    $user = $this->identity();
    
    // If this is an admin user, redirect to actual admin dashboard
    if ($this->userHasAdminAccess($user)) {
        return $this->redirect()->toUrl('/admin');
    }
    
    // Get the user's items and uploads
    $userId = $user->getId();
    $items = [];
    $itemSets = [];
    
    error_log("=== MY DATA DEBUG for User ID: $userId ===", 3, OMEKA_PATH . '/logs/my-data-debug.log');
    
    try {
        // Strategy 1: Try to get items created by this user
        $response = $this->api()->search('items', [
            'owner_id' => $userId,
            'sort_by' => 'created',
            'sort_order' => 'desc',
            'limit' => 50
        ]);
        $items = $response->getContent();
        error_log("Strategy 1 - Found " . count($items) . " items by owner_id", 3, OMEKA_PATH . '/logs/my-data-debug.log');
        
        // Strategy 2: Also get item sets created by this user
        $itemSetResponse = $this->api()->search('item_sets', [
            'owner_id' => $userId,
            'sort_by' => 'created', 
            'sort_order' => 'desc',
            'limit' => 50
        ]);
        $itemSets = $itemSetResponse->getContent();
        error_log("Strategy 2 - Found " . count($itemSets) . " item sets by owner_id", 3, OMEKA_PATH . '/logs/my-data-debug.log');
        
        // Strategy 3: If no items found, try searching all items and filter by current user manually
        if (empty($items)) {
            error_log("No items found by owner_id, trying manual search", 3, OMEKA_PATH . '/logs/my-data-debug.log');
            
            // Get all items and manually filter
            $allItemsResponse = $this->api()->search('items', [
                'sort_by' => 'created',
                'sort_order' => 'desc',
                'limit' => 200 // Increase limit to catch more items
            ]);
            $allItems = $allItemsResponse->getContent();
            
            error_log("Found " . count($allItems) . " total items in system", 3, OMEKA_PATH . '/logs/my-data-debug.log');
            
            foreach ($allItems as $item) {
                $owner = $item->owner();
                if ($owner && $owner->id() == $userId) {
                    $items[] = $item;
                    error_log("Found user's item: " . $item->displayTitle() . " (ID: " . $item->id() . ")", 3, OMEKA_PATH . '/logs/my-data-debug.log');
                }
            }
        }
        
        // Strategy 4: Also check for items in item sets owned by this user
        foreach ($itemSets as $itemSet) {
            $itemSetItems = $this->api()->search('items', [
                'item_set_id' => $itemSet->id(),
                'limit' => 50
            ])->getContent();
            
            foreach ($itemSetItems as $item) {
                // Add to items list if not already there
                $found = false;
                foreach ($items as $existingItem) {
                    if ($existingItem->id() == $item->id()) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $items[] = $item;
                    error_log("Found item in user's item set: " . $item->displayTitle() . " (ID: " . $item->id() . ")", 3, OMEKA_PATH . '/logs/my-data-debug.log');
                }
            }
        }
        
        // FILTER OUT non-arrowhead items
        $filteredItems = [];
        foreach ($items as $item) {
            // Check if it's an arrowhead by:
            // 1. Looking at resource class
            // 2. Looking for specific properties (arrowhead shape, variant, etc.)
            // 3. Looking at the title pattern
            
            $isArrowhead = false;
            
            // Method 1: Check resource class
            $resourceClass = $item->resourceClass();
            if ($resourceClass && strpos(strtolower($resourceClass->label()), 'arrowhead') !== false) {
                $isArrowhead = true;
            }
            
            // Method 2: Check for arrowhead-specific properties
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
            
            // Method 3: Check title patterns
            if (!$isArrowhead) {
                $title = $item->displayTitle();
                if (strpos(strtolower($title), 'arrowhead') !== false || 
                    strpos($title, 'AH-') === 0 ||
                    preg_match('/^(?:item|archaeological item)\s+AH-/i', $title)) {
                    $isArrowhead = true;
                }
            }
            
            // Method 4: Exclude known non-arrowhead types
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
                error_log("Including arrowhead item: " . $item->displayTitle(), 3, OMEKA_PATH . '/logs/my-data-debug.log');
            } else {
                error_log("Excluding non-arrowhead item: " . $item->displayTitle(), 3, OMEKA_PATH . '/logs/my-data-debug.log');
            }
        }
        
        // Replace the original items array with the filtered one
        $items = $filteredItems;
        
    } catch (\Exception $e) {
        error_log('Error loading user items: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/my-data-debug.log');
        $this->messenger()->addError('Failed to load your items: ' . $e->getMessage());
    }
    
    error_log("Final count - Arrowhead Items: " . count($items) . ", Item Sets: " . count($itemSets), 3, OMEKA_PATH . '/logs/my-data-debug.log');
    
    // Show user data page
    $view = new ViewModel([
        'user' => $user,
        'site' => $this->currentSite(),
        'items' => $items,
        'itemSets' => $itemSets, // Add item sets to view
        'totalItems' => count($items),
        'totalItemSets' => count($itemSets), // Add count
        'isLoggedIn' => true,
        'userRole' => $user->getRole()
    ]);
    $view->setTemplate('add-triplestore/site/index/my-data');
    
    return $view;
}


/**
 * Handle user login for the Add Triplestore module.
 *
 * This action processes login requests for both admin and guest users.
 * If the user is already logged in, they are redirected to the appropriate
 * dashboard based on their role. Guest users are directed to a custom dashboard
 * while admin users are sent to the admin area.
 *
 * The method uses Omeka's authentication service and validates login credentials.
 * Failed login attempts are logged for debugging purposes along with validation errors.
 *
 * @return \Laminas\View\Model\ViewModel|\Laminas\Http\Response The login form view or
 *         a redirect response if already logged in or after successful authentication
 */
public function loginAction()
{
    error_log('Login action called', 3, OMEKA_PATH . '/logs/login-debug.log');
    // If already logged in, redirect to main page
    if ($this->identity()) {
        $user = $this->identity();
        
        // Check if user is a guest/site-only user
        if ($user->getRole() === 'guest') {
            // Guest users go to custom dashboard
            return $this->redirect()->toRoute('site/add-triplestore/dashboard', [
                'site-slug' => $this->currentSite()->slug()
            ]);
        } else {
            // Admin users can go to admin dashboard
            return $this->redirect()->toUrl('/admin');
        }
    }

    $form = $this->getServiceLocator()->get('FormElementManager')->get(\Omeka\Form\LoginForm::class);
    $view = new ViewModel([
        'form' => $form,
        'site' => $this->currentSite()
    ]);
    $view->setTemplate('add-triplestore/site/index/login');
    error_log('Rendering login form', 3, OMEKA_PATH . '/logs/login-debug.log');
    
    if ($this->getRequest()->isPost()) {
        // Get POST data and add the CSRF security element
        $data = $this->params()->fromPost();
        
        // Check if csrf element exists in the form
        $csrfElement = $form->get('loginform_csrf');
        if ($csrfElement) {
            // If CSRF element exists in the form but not in data, add it
            if (!isset($data['loginform_csrf'])) {
                $data['loginform_csrf'] = $csrfElement->getValue();
            }
        }
        
        $form->setData($data);
        error_log('Form data received: ' . print_r($data, true), 3, OMEKA_PATH . '/logs/login-debug.log');
        
        error_log('Form validation started', 3, OMEKA_PATH . '/logs/login-debug.log');
        // log form error messages
        if (!$form->isValid()) {
            $errors = $form->getMessages();
            error_log('Form validation errors: ' . print_r($errors, true), 3, OMEKA_PATH . '/logs/login-debug.log');
        }
        if ($form->isValid()) {
            $validatedData = $form->getData();
            $sessionManager = Container::getDefaultManager();
            $sessionManager->regenerateId();
            
            // Use Omeka's authentication service directly
            $authService = $this->getServiceLocator()->get('Omeka\AuthenticationService');
            $adapter = $authService->getAdapter();
            $adapter->setIdentity($validatedData['email']);
            $adapter->setCredential($validatedData['password']);
            
            // Log the login attempt for debugging
            error_log("Login attempt for: " . $validatedData['email'], 3, OMEKA_PATH . '/logs/login-debug.log');
            
            $result = $authService->authenticate();
            
            if ($result->isValid()) {
                // Log successful login
                error_log("Successful login for: " . $validatedData['email'], 3, OMEKA_PATH . '/logs/login-debug.log');
                
                // Check if user is a guest/site-only user
                $user = $authService->getIdentity();
                error_log("User role: " . $user->getRole(), 3, OMEKA_PATH . '/logs/login-debug.log');
                
                if ($user->getRole() === 'guest') {
                    // Guest users go to custom dashboard
                    return $this->redirect()->toRoute('site/add-triplestore/dashboard', [
                        'site-slug' => $this->currentSite()->slug()
                    ]);
                } else {
                    // Admins can go to regular admin area
                    return $this->redirect()->toUrl('/admin');
                }
            } else {
                // Log authentication error details
                error_log("Login failed for: " . $validatedData['email'] . ". Reason: " . $result->getMessages()[0], 3, OMEKA_PATH . '/logs/login-debug.log');
                $this->messenger()->addError('Email or password is invalid');
            }
        } else {
            $this->messenger()->addError('Email or password is invalid');
        }
    }
    
    return $view;
}


/**
 * Checks if a user is logged in and redirects to login page if not.
 * 
 * This method verifies if there is an authenticated user (identity).
 * If no user is logged in, it adds an error message and redirects to the login page.
 * If a user is logged in, it logs their email and role for debugging purposes.
 * All authenticated users, including those with guest roles, are allowed to proceed.
 * 
 * @return \Laminas\Http\Response|null Returns a redirect response if login is required, 
 *                                      or null if the user is authenticated
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
    error_log('User in requireLogin: ' . $user->getEmail() . ' with role: ' . $user->getRole(), 3, OMEKA_PATH . '/logs/access-check.log');
    
    // Allow guest users to proceed
    return null;
}






/**
 * Creates and configures a signup form for user registration.
 * 
 * This method builds a form with the following fields:
 * - Full Name (text field, required)
 * - Email (email field, required)
 * - Password (password field, required)
 * - Confirm Password (password field, required)
 * - Submit button
 * 
 * All input fields include Bootstrap's 'form-control' class for styling.
 * 
 * @return \Laminas\Form\Form The configured signup form
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



/**
 * Site page default action
 *
 * Renders the main page of the AddTriplestore module in the site context.
 * Checks if the user is currently logged in and passes this status to the view
 * along with the current site information.
 *
 * @return \Laminas\View\Model\ViewModel The view model with site and login status
 */
public function indexAction()
{
    // Check if user is logged in for functionality access
    $site = $this->currentSite();
    $isLoggedIn = (bool) $this->identity();
    
    return new ViewModel([
        'site' => $site,
        'isLoggedIn' => $isLoggedIn
    ]);
}
    

    

/**
 * Get Turtle (TTL) format prefixes for RDF serialization.
 *
 * This method returns a string containing standard namespace prefixes used in RDF/Turtle serialization.
 * The prefixes include common ontologies and vocabularies such as RDF, RDFS, SHACL, SKOS, Dublin Core,
 * FOAF, DBpedia, CIDOC-CRM (and its extensions), Europeana Data Model, geo vocabulary, and others.
 *
 * @return string A string containing Turtle prefixes declarations
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
 * Sanitizes a string to make it suitable for use in a URI.
 * 
 * This method performs the following transformations:
 * 1. Extracts text before parentheses if present
 * 2. Converts the string to lowercase
 * 3. Removes spaces and parentheses
 * 
 * @param string $value The string to be sanitized
 * @return string The sanitized string suitable for URI use
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
 * Handle various types of uploads in the AddTriplestore module.
 * 
 * This controller action handles multiple upload scenarios:
 * - Arrowhead file uploads to an existing excavation/item set
 * - Arrowhead form submissions with metadata
 * - Excavation form submissions (creating new excavation item sets)
 * - Direct file uploads (TTL or other formats)
 * 
 * The action performs authentication checks and supports different modes of operation:
 * - 'file' mode: For direct file uploads via HTML file input
 * - 'form' mode: For structured data entry via web forms
 * 
 * For excavation uploads, the action automatically creates an item set and then
 * redirects to the arrowhead upload interface to allow adding artifacts to the
 * excavation. The method handles various error conditions and permissions.
 * 
 * @return mixed Either a ViewModel for rendering upload forms or a redirect response
 *               after processing uploads/form submissions
 */
public function uploadAction()
{
    // Get all POST data
    $redirect = $this->requireLogin();
    if ($redirect) return $redirect;

    // Log the current user role for debugging
    $user = $this->identity();
    error_log('User attempting upload: ' . $user->getEmail() . ' with role: ' . $user->getRole(), 3, OMEKA_PATH . '/logs/upload-access.log');

    $postData = $this->params()->fromPost();

    // Check if this is a continuous arrowhead upload
    $uploadType = $this->params()->fromQuery('upload_type') ?: $this->params()->fromPost('upload_type');
    $itemSetId = $this->params()->fromQuery('item_set_id') ?: $this->params()->fromPost('item_set_id');
    $mode = $this->params()->fromQuery('mode', $this->params()->fromPost('mode', 'upload'));

    error_log('going for if is upload action' . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
    
    // Process arrowhead file upload
    if ($mode == 'file' && $uploadType == 'arrowhead' && $itemSetId) {
        $file = $this->params()->fromFiles('file');
        if ($file && !empty($file['tmp_name'])) {
            // Process the uploaded file
            error_log('File upload detected: ' . $file['name'], 3, OMEKA_PATH . '/logs/aaaaaaaaaaa.log');
            $result = $this->processFileUpload($this->getRequest(), $uploadType, $itemSetId);
            error_log('Arrowhead upload result: ' . $result, 3, OMEKA_PATH . '/logs/aaaaaaaaaaa.log');
            // Check if excavation ID is available for a more specific message
            $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
            error_log('Excavation ID: ' . $excavationId, 3, OMEKA_PATH . '/logs/aaaaaaaaaaa.log');
            if ($excavationId && strpos($result, 'successfully') !== false) {
                $result = "Arrowhead was successfully added to excavation $excavationId (Item Set #$itemSetId). You can upload another or click Exit when done.";
            }
            
            // Redirect back to the same page to enable continuous uploads
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
        
        // Show arrowhead upload form
        $view = new ViewModel([
            'itemSetId' => $itemSetId,
            'uploadType' => $uploadType,
            'result' => $this->params()->fromQuery('result')
        ]);
        $view->setTemplate('add-triplestore/site/index/upload-arrowhead');
        return $view;
    }

    // Revised fix for the arrowhead form processing
    if ($mode == 'form' && $uploadType == 'arrowhead') {
        // Check if we have POST data (a form submission)
        $formData = $this->params()->fromPost();
        
        // Only process if there's actual form data and no success flag in the query
        $success = $this->params()->fromQuery('success', false);
        
        if (!empty($formData) && empty($success)) {
            // This is a real form submission, process it
            $ttlData = $this->processArrowheadFormData($formData, $itemSetId);
            error_log('Creating arrowhead with id: ' . $formData['arrowhead_identifier'], 3, OMEKA_PATH . '/logs/new-aux.log');
            // log ttl data for debugging
            error_log('TTL data: ' . $ttlData, 3, OMEKA_PATH . '/logs/new-aux-ttl.log');
            // Upload TTL data to triplestore
            $result = $this->uploadTtlData($ttlData, $itemSetId) ?? 'Unknown error occurred during upload';
            error_log('Arrowhead upload result: ' . $result, 3, OMEKA_PATH . '/logs/new-aux.log');
            
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
            
            error_log('Redirecting to URL: ' . $url, 3, OMEKA_PATH . '/logs/new-aux.log');
            return $this->redirect()->toUrl($url);
        } else {
            // Either this is just a page view, or we're viewing after a success
            // Simply render the template with proper variables
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
        // Get all POST data from the collecting form
        $formData = $this->params()->fromPost();
        
        error_log('Received excavation collecting form data: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/excavation-collecting-form.log');
        
        // Transform collecting form data to excavation format
        $excavationData = $this->transformCollectingFormToExcavationData($formData);
        
        if (!empty($excavationData)) {
            // Generate excavation identifier
            $excavationIdentifier = $excavationData['excavation_id'] ?? 'EXC-' . uniqid();
            
            // Create TTL data from the excavation form
            $ttlData = $this->processExcavationFormData($excavationData, $excavationIdentifier);
            
            // Create item set first
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
                    
                    error_log('Excavation form processing result: ' . $result, 3, OMEKA_PATH . '/logs/excavation-collecting-form.log');
                    
                    // Redirect to arrowhead upload page with success message
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
                // Check specifically for permission errors
                if (strpos($e->getMessage(), 'permission') !== false) {
                    error_log('Permission error during excavation creation: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/permission-error.log');
                    $this->messenger()->addError('You do not have permission to create excavations. Please contact an administrator.');
                } else {
                    error_log('Error creating excavation item set: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-collecting-form.log');
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
        
        // If transformation failed, redirect with error
        return $this->redirect()->toUrl($this->url()->fromRoute('site', [
            'site-slug' => $this->currentSite()->slug()
        ], [
            'query' => [
                'result' => 'Error: Could not process excavation form data'
            ]
        ]));
    }
    
    // For direct file uploads - handle normally
    else if (isset($_FILES['file']) && !empty($_FILES['file']['tmp_name'])) {
        // Log the upload type for debugging
        error_log('going to upload file' . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
        
        $result = $this->processFileUpload($this->getRequest(), $uploadType, $itemSetId);
        error_log('File upload result: ' . $result  . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
        
        // If this is an excavation file upload, create an item set if needed and redirect to arrowhead upload
        if ($uploadType == 'excavation') {
            error_log('going for excav uplotad file' . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');

            error_log('Processing excavation file upload', 3, OMEKA_PATH . '/logs/a.log');
            // Extract excavation identifier from the upload result
            error_log('Upload result: ' . $result, 3, OMEKA_PATH . '/logs/a.log');
            preg_match('/Excavation ([A-Za-z0-9-]+)/', $result, $matches);
            $excavationIdentifier = isset($matches[1]) ? $matches[1] : null;
            error_log('Excavation identifier: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/a.log');
            if ($excavationIdentifier) {
                // Get the item set ID either from the upload result or from the mapping
                if (strpos($result, 'Item Set #') !== false) {
                    preg_match('/Item Set #(\d+)/', $result, $matches);
                    $itemSetId = isset($matches[1]) ? $matches[1] : null;
                }
                
                if ($itemSetId) {
                    error_log('there is item set' . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');

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
        
        // For excavation file uploads, make sure we redirect to a page where arrowheads can be added
        if ($uploadType == 'excavation' && strpos($result, 'successfully') !== false) {
            // Try to extract item set ID from the result
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
        
        // Normal redirect to the index page with the result if we couldn't determine a better redirect
        return $this->redirect()->toUrl($this->url()->fromRoute('site', [
            'site-slug' => $this->currentSite()->slug()
        ], [
            'query' => [
                'result' => $result,
                'item_set_id' => $itemSetId
            ]
        ]));
    }
    
    // Default response if no specific upload type was recognized
    return $this->redirect()->toUrl($this->url()->fromRoute('site', ['site-slug' => $this->currentSite()->slug()]));
}


/**
 * Determines whether the current authenticated user has permission to create a resource.
 *
 * This method checks if the current user has the necessary permissions to create
 * a resource of the specified type using Omeka's ACL (Access Control List) system.
 * The permission check result is logged in the application's permission-check.log file.
 *
 * @param string $resourceType The type of resource to check permission for (e.g., 'Item', 'ItemSet')
 * @return bool Returns true if the user has permission to create the resource, false otherwise
 */
private function canUserCreateResource($resourceType) 
{
    $user = $this->identity();
    if (!$user) {
        return false;
    }
    
    // Get the ACL service
    $acl = $this->getServiceLocator()->get('Omeka\Acl');
    
    // Check if the user has permission to create this resource type
    $canCreate = $acl->userIsAllowed("Omeka\Entity\\$resourceType", 'create');
    
    error_log("Permission check for user {$user->getEmail()} to create $resourceType: " . ($canCreate ? 'ALLOWED' : 'DENIED'), 
              3, OMEKA_PATH . '/logs/permission-check.log');
              
    return $canCreate;
}


/**
 * Process archaeologist data from submitted form.
 *
 * This method extracts and normalizes archaeologist information from form data.
 * It handles two cases:
 * 1. When an existing archaeologist is selected - retrieves their data from Omeka
 * 2. When a new archaeologist is being added - uses directly submitted form values
 *
 * @param array $formData The submitted form data containing archaeologist information
 * 
 * @return array Normalized archaeologist data with the following structure:
 *   - 'existing': bool - Whether this references an existing archaeologist
 *   - 'item_id': int|null - If existing, the Omeka item ID of the archaeologist
 *   - 'name': string|null - The archaeologist's name
 *   - 'orcid': string|null - The archaeologist's ORCID identifier (without URL prefix)
 *   - 'email': string|null - The archaeologist's email (without mailto: prefix)
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
            error_log('Error loading existing archaeologist: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-transform.log');
        }
    } else {
        // New archaeologist data
        $archaeologistData['existing'] = false;
        $archaeologistData['name'] = $formData['new_archaeologist_name'] ?? null;
        $archaeologistData['orcid'] = $formData['new_archaeologist_orcid'] ?? null;
        $archaeologistData['email'] = $formData['new_archaeologist_email'] ?? null;
    }
    
    return $archaeologistData;
}






/**
 * Convert a string into a URL-friendly slug
 *
 * This method processes a string to create a URL-safe slug by:
 * - Converting all characters to lowercase
 * - Replacing spaces and special characters with hyphens
 * - Removing leading and trailing hyphens
 * - Setting a default value if the result is empty
 *
 * @param string $string The input string to be converted to a slug
 * @return string The formatted URL slug
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
 * Processes archaeologist data to generate a URI for use in TTL format.
 *
 * This method handles two scenarios:
 * 1. For existing archaeologists: Creates a URI based on the item ID
 * 2. For new archaeologists: Creates a URI using a slug generated from the archaeologist's name
 *
 * @param array $archaeologistData Array containing archaeologist information ('existing', 'item_id', 'name')
 * @param string $baseUri Base URI to prefix the generated path
 * 
 * @return string|null The generated URI for the archaeologist or null if required data is missing
 */
private function processArchaeologistForTtl($archaeologistData, $baseUri)
{
    if ($archaeologistData['existing'] && !empty($archaeologistData['item_id'])) {
        // Use existing archaeologist - create URI based on item ID
        return "$baseUri/archaeologist/item-" . $archaeologistData['item_id'];
    } elseif (!empty($archaeologistData['name'])) {
        // Create new archaeologist with readable URI
        $nameSlug = $this->createUrlSlug($archaeologistData['name']);
        return "$baseUri/archaeologist/$nameSlug";
    }
    
    return null;
}



/**
 * Generates Turtle (TTL) RDF representation for a context entity.
 *
 * This method creates a standardized Turtle syntax representation of an archaeological context,
 * including its identifier, description, and relationships with SVUs (Stratigraphic Volume Units).
 * 
 * @param string $contextUri The URI that will identify this context in the triplestore
 * @param array $context An associative array containing the context data (context_id, context_description)
 * @param array $allEntities A nested array containing all related entities including:
 *                          - contexts: Array of all context entities
 *                          - svus: Array of all SVU entities
 *                          - relationships: Array of relationship mappings between contexts and SVUs
 * @param string $baseUri The base URI used to construct full URIs for related entities
 * 
 * @return string A formatted Turtle (TTL) string representing the context and its relationships
 */

private function generateContextTtl($contextUri, $context, $allEntities, $baseUri)
{
    $ttl = "<$contextUri> a excav:Context ;\n";
    $ttl .= "    dct:identifier \"" . $context['context_id'] . "\"^^xsd:literal ;\n";
    
    if (!empty($context['context_description'])) {
        $ttl .= "    dct:description \"" . $context['context_description'] . "\"^^xsd:literal ;\n";
    }
    
    // Link to SVUs based on relationships
    if (!empty($allEntities['relationships'])) {
        foreach ($allEntities['relationships'] as $relationship) {
            // Find the context index that matches this context
            $contextFound = false;
            foreach ($allEntities['contexts'] as $ctxIndex => $ctx) {
                if ($ctx['context_id'] === $context['context_id'] && $relationship['context'] == $ctxIndex) {
                    $contextFound = true;
                    break;
                }
            }
            
            if ($contextFound && isset($allEntities['svus'][$relationship['svu']])) {
                $svu = $allEntities['svus'][$relationship['svu']];
                $svuUri = "$baseUri/svu/" . $this->sanitizeForUri($svu['svu_id']);
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
 * This method creates Turtle format RDF data that enhances a location with
 * additional geographic and excavation information.
 *
 * @param string $locationUri The URI identifier for the location
 * @param string $gpsUri The URI for the GPS coordinate reference
 * @param array $excavationData Data about the excavation associated with the location
 * @return string The generated Turtle (TTL) format data
 */
private function generateEnhancedLocationTtl($locationUri, $gpsUri, $excavationData)
{
    $ttl = "";

    // check if there is location data

    $hasLocationData = !empty($excavationData['site_name']) ||
                      !empty($excavationData['district']) ||
                      !empty($excavationData['parish']) ||
                      !empty($excavationData['country']) ||
                      (!empty($excavationData['latitude']) && !empty($excavationData['longitude']));
    
    if (!$hasLocationData) {
        error_log("No location data available - skipping location TTL generation", 3, OMEKA_PATH . '/logs/location-debug.log');
        return null;
    }       
    
    // MAIN LOCATION ENTITY - clean single type
    $ttl .= "<$locationUri> a excav:Location ;\n";
    
    if (!empty($excavationData['site_name'])) {
        $ttl .= "    dbo:informationName \"" . $excavationData['site_name'] . "\"^^xsd:literal ;\n";
    }
    
    // Add District/parish with lowercase property names and normalized URIs
    $baseUri = dirname(dirname($locationUri)); // Get base URI from location URI
    $entitiesToDeclare = [];
    
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
    
    // Country as DBpedia resource (uppercase 'Country')
    if (!empty($excavationData['country'])) {
        $countrySlug = str_replace(' ', '_', $excavationData['country']);
        $countryUri = "http://dbpedia.org/resource/" . $countrySlug;
        $ttl .= "    dbo:Country <$countryUri> ;\n";
        $entitiesToDeclare['country'] = [
            'uri' => $countryUri,
            'label' => $excavationData['country']
        ];
    }
    
    // Only add GPS references if both latitude and longitude are provided
    if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
        $ttl .= "    excav:hasGPSCoordinates <$gpsUri> ;\n";
    }

    $ttl .= ".\n";
    if (!empty($excavationData['latitude']) && !empty($excavationData['longitude'])) {
        $ttl .= "<$gpsUri> a excav:GPSCoordinates ;\n";
        $ttl .= "    geo:lat \"" . $excavationData['latitude'] . "\"^^xsd:decimal ;\n";
        $ttl .= "    geo:long \"" . $excavationData['longitude'] . "\"^^xsd:decimal .\n\n";
    }

    
    // Add type declarations for referenced entities
    if (!empty($entitiesToDeclare)) {
        $ttl .= "# Type declarations for referenced resources\n";
        
        if (isset($entitiesToDeclare['district'])) {
            $ttl .= "<{$entitiesToDeclare['district']['uri']}> a dbo:District .\n";
        }
        
        if (isset($entitiesToDeclare['parish'])) {
            $ttl .= "<{$entitiesToDeclare['parish']['uri']}> a dbo:Parish .\n";
        }
        // ADD THIS: Generate Country type declaration
        if (isset($entitiesToDeclare['country'])) {
            $ttl .= "<{$entitiesToDeclare['country']['uri']}> a dbo:Country .\n";
        }
        
        $ttl .= "\n";
    }
    
    return $ttl;
}


/**
 * Normalizes URIs in Turtle (TTL) data for use within the local system.
 * 
 * This method converts public URIs (https://purl.org/megalod/) to local URIs (http://localhost/megalod/) 
 * with a hierarchical structure based on the item set ID and excavation identifier. The normalization
 * ensures consistent URI patterns across the local system while maintaining the relationships between entities.
 * 
 * The method handles various entity types including:
 * - Excavation
 * - Location
 * - GPS coordinates
 * - Archaeologist
 * - Square
 * - Context
 * - SVU (Stratigraphic Volume Unit)
 * - Timeline and Instant
 * - Items and their properties (typometry, coordinates, weight, morphology, etc.)
 * - Encounters
 * 
 * All Knowledge Organization System (KOS) URIs are preserved in their original form.
 * 
 * @param string $ttlData The Turtle data to normalize
 * @param int $itemSetId The item set ID to use for creating new URIs
 * 
 * @return string Normalized Turtle data with local URIs
 */
private function normalizeUris($ttlData, $itemSetId) {
    error_log("=== FIXED URI NORMALIZATION START ===", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    error_log("ItemSetId: $itemSetId", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    
    if (!$itemSetId) {
        error_log("No item set ID provided, skipping normalization", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
        return $ttlData;
    }
    
    // 1. First, extract the main excavation identifier
    $excavationIdentifier = null;
    error_log("item set: $itemSetId", 3, OMEKA_PATH . '/logs/uri-normalize-fix.log');
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
    if ($excavationIdentifier == null){
        if (preg_match('/dct:identifier\s+"([^"]+)"/i', $ttlData, $matches)) {
            $excavationIdentifier = $matches[1]; // e.g., "alto-castelinho-2024"
            error_log("Main excavation identifier found: $excavationIdentifier", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
        } else {
            // Default to item set ID if no specific identifier found
            $excavationIdentifier = "excavation-$itemSetId";
            error_log("No excavation identifier found, using default: $excavationIdentifier", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
        }
    }
    error_log("Excavation identifier used for normalization: $excavationIdentifier", 3, OMEKA_PATH . '/logs/uri-normalize-fix.log');
    
    $modifiedTtl = $ttlData;
    $replacements = 0;
    
    // 2. Main excavation URI pattern
    $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/excavation\/([^>]+)>/',
        function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
            if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
            $replacements++;
            error_log("Replacing excavation URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier>";
        },
        $modifiedTtl
    );
    
    // 3. Location URI pattern
    $modifiedTtl = preg_replace_callback(
    '/<https:\/\/purl\.org\/megalod\/([^\/]+\/)?location\/([^>]+)>/',
    function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
        if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
        $locationId = $matches[2]; // Get the location identifier
        $replacements++;
        $newUri = "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/location/$locationId>";
        error_log("Replacing location URI: {$matches[0]} → $newUri", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
        return $newUri;
    },
    $modifiedTtl
    );
        
        // 4. GPS URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/gps\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
                $gpsId = $matches[1];
                $replacements++;
                error_log("Replacing GPS URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/gps/$gpsId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/gps/$gpsId>";
            },
            $modifiedTtl
        );
        
        // 5. Archaeologist URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/archaeologist\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
                $archaeologistId = $matches[1];
                $replacements++;
                error_log("Replacing archaeologist URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/archaeologist/$archaeologistId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/archaeologist/$archaeologistId>";
            },
            $modifiedTtl
        );
        
        // 6. Square URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/square\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
                $squareId = $matches[1];
                $replacements++;
                error_log("Replacing square URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/$squareId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/$squareId>";
            },
            $modifiedTtl
        );
        
        // 7. Context URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/context\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
                $contextId = $matches[1];
                $replacements++;
                error_log("Replacing context URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/context/$contextId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/context/$contextId>";
            },
            $modifiedTtl
        );
        
        // 8. SVU URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/svu\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
                $svuId = $matches[1];
                $replacements++;
                error_log("Replacing SVU URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/svu/$svuId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/svu/$svuId>";
            },
            $modifiedTtl
        );
        
        // 9. Timeline URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/timeline\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
                $timelineId = $matches[1];
                $replacements++;
                error_log("Replacing timeline URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/timeline/$timelineId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/timeline/$timelineId>";
            },
            $modifiedTtl
        );
        
        // 10. Instant URI pattern
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/instant\/([^>]+)>/',
            function($matches) use ($itemSetId, $excavationIdentifier, &$replacements) {
                if (strpos($matches[0], '/kos/') !== false) return $matches[0]; // Preserve KOS URIs
                $instantId = $matches[1];
                $replacements++;
                error_log("Replacing instant URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/instant/$instantId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/instant/$instantId>";
            },
            $modifiedTtl
        );
        
        // 11. Special case for KOS URIs - always preserve the original path
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/[^>]*\/kos\/([^>]+)>/',
            function($matches) {
                return "<https://purl.org/megalod/kos/{$matches[1]}>";
            },
            $modifiedTtl
        );

        // 12. MODIFIED: ITEM URI PATTERN - use /itemsetid/item/ pattern

        // get the item identifier
        $itemIdentifier = null;
        if (preg_match('/dct:identifier\s+"([^"]+)"/i', $modifiedTtl, $matches)) {
            $itemIdentifier = $matches[1]; 
            error_log("Item identifier found: $itemIdentifier", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
        } else {
            // Default to item set ID if no specific identifier found
            $itemIdentifier = "item-$itemSetId";
            error_log("No item identifier found, using default: $itemIdentifier", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
        }
        
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/item\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $replacements++;
                error_log("Replacing item URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier>";
            },
            $modifiedTtl
        );

        // 13. Normalize typometry URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/typometry\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $typometryId = $matches[1];
                $replacements++;
                error_log("Replacing typometry URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/typometry/$typometryId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/typometry/$typometryId>";
            },
            $modifiedTtl
        );
        
        // 14. Normalize coordinates URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/coordinates\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $coordinatesId = $matches[1];
                $replacements++;
                error_log("Replacing coordinates URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/coordinates/$coordinatesId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/coordinates/$coordinatesId>";
            },
            $modifiedTtl
        );

        // 15. Normalize weight URIs  
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/weight\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $weightId = $matches[1];
                $replacements++;
                error_log("Replacing weight URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/weight/$weightId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/weight/$weightId>";
            },
            $modifiedTtl
        );
        
        // 16. Normalize morphology URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/Morphology\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $morphologyId = $matches[1];
                $replacements++;
                error_log("Replacing morphology URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/morphology/$morphologyId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/morphology/$morphologyId>";
            },
            $modifiedTtl
        );
        
        // 17. Normalize body length URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/BodyLength\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $bodyLengthId = $matches[1];
                $replacements++;
                error_log("Replacing body length URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/bodylength/$bodyLengthId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/bodylength/$bodyLengthId>";
            },
            $modifiedTtl
        );
        
        // 18. Normalize base length URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/BaseLength\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $baseLengthId = $matches[1];
                $replacements++; 
                error_log("Replacing base length URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/baselength/$baseLengthId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/baselength/$baseLengthId>";
            },
            $modifiedTtl
        );
        
        // 19. Normalize chipping URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/Chipping\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $chippingId = $matches[1];
                $replacements++;
                error_log("Replacing chipping URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/chipping/$chippingId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/chipping/$chippingId>";
            },
            $modifiedTtl
        );

        // 19.5 normalize gps coordinates URIs
        $modifiedTtl = preg_replace_callback(
            '/<https:\/\/purl\.org\/megalod\/gps\/([^>]+)>/',
            function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
                $gpsId = $matches[1];
                $replacements++;
                error_log("Replacing GPS coordinates URI: {$matches[0]} → <http://localhost/megalod/$itemSetId/item/$itemIdentifier/gps/$gpsId>", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
                return "<http://localhost/megalod/$itemSetId/item/$itemIdentifier/gps/$gpsId>";
            },
            $modifiedTtl
        );
        
        // 20. Normalize encounter URIs
        $modifiedTtl = preg_replace_callback(
        '/<https:\/\/purl\.org\/megalod\/encounter\/([^>]+)>/',
        function($matches) use ($itemSetId, $itemIdentifier, &$replacements) {
            $encounterId = $matches[1];
            $replacements++;
            
            // Get excavation identifier for the item set
            $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId) ?: "excavation";
            
            // Create encounter URI with correct path structure including excavation identifier
            $newUri = "<http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/encounter/$encounterId>";
            
            error_log("Replacing encounter URI: {$matches[0]} → $newUri", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
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
            
            // Get excavation identifier for the item set
            $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($setId) ?: "excavation";
            
            // Create encounter URI with correct path structure
            $newUri = "<http://localhost/megalod/$setId/excavation/$excavationIdentifier/encounter/$encounterId>";
            
            $replacements++;
            error_log("Normalizing encounter URI structure: {$matches[0]} → $newUri", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
            return $newUri;
        },
        $modifiedTtl
    );
    

    // 22. Fix any malformed URIs with double angle brackets
    $modifiedTtl = str_replace('<<', '<', $modifiedTtl);
    $modifiedTtl = str_replace('>>', '>', $modifiedTtl);
    
    error_log("=== URI NORMALIZATION COMPLETE ===", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    error_log("Total replacements made: $replacements", 3, OMEKA_PATH . '/logs/uri-normalize-fixed.log');
    
    // Log the final modified TTL for debugging
    error_log("Modified TTL:\n$modifiedTtl", 3, OMEKA_PATH . '/logs/fixed.log');
    return $modifiedTtl;
}

/**
 * Generates Turtle (TTL) format RDF data for a "Smallest Viable Unit" (SVU).
 * 
 * This method converts an SVU entity to TTL format for RDF representation.
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
    
    // Add timeline if year data is provided - use consistent URI structure
    if (!empty($svu['svu_lower_year']) || !empty($svu['svu_upper_year'])) {
        // Extract base URI from SVU URI to build timeline URI
        $baseUri = dirname(dirname($svuUri)); // Get the base URI (e.g., http://localhost/megalod/2422)
        $svuSlug = basename($svuUri); // Get just the SVU identifier part
        $timelineUri = "$baseUri/timeline/$svuSlug";
        
        $ttl .= "    excav:hasTimeline <$timelineUri> ;\n";
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}

/**
 * Process excavation form data to generate TTL (Turtle) RDF representation.
 *
 * This method takes excavation data submitted from a form and transforms it into
 * a structured RDF Turtle format for storage in a triplestore. The method creates
 * URIs for the excavation and associated entities (locations, archaeologists,
 * squares, contexts, SVUs, etc.) and organizes them according to the MegaLOD ontology.
 *
 * The generated TTL includes several sections:
 * - Main excavation data
 * - Location information (if available)
 * - Archaeologist details
 * - Square definitions
 * - Context information
 * - Stratigraphic Volume Units (SVUs)
 * - Timeline and instant data (for temporal representation)
 *
 * @param array $excavationData The structured excavation data from form submission
 * @param string $excavationIdentifier Unique identifier for the excavation
 * 
 * @return string Complete TTL representation of the excavation data
 * 
 * @logs Writes processing information to excavation-ttl.log
 */
private function processExcavationFormData($excavationData, $excavationIdentifier)
{
    error_log('Processing excavation form data for: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-ttl.log');
    
    // USE THE EXCAVATION IDENTIFIER INSTEAD OF RANDOM HASH
    $baseUri = "https://purl.org/megalod/" . $excavationIdentifier;
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
    
    // Add excavation
    $ttl .= "<$excavationUri> a excav:Excavation ;\n";
    $ttl .= "    dct:identifier \"$excavationIdentifier\"^^xsd:literal ;\n";
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
            error_log("Processing SVU: " . $svu['svu_id'], 3, OMEKA_PATH . '/logs/excavation-ttl-a.log');
            $svuSlug = $this->createUrlSlug($svu['svu_id']);
            $svuUri = "$baseUri/svu/$svuSlug";
            $ttl .= $this->generateSvuTtl($svuUri, $svu);
        }
    }
    
    // Generate timeline and instant sections if we have SVUs with dates
    $this->generateTimelineAndInstantSections($ttl, $excavationData, $baseUri);
    
    error_log('Generated clean TTL for excavation: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/excavation-ttl.log');
    
    return $ttl;
}




/**
 * Generates timeline and time instant sections in Turtle (TTL) format for excavation data.
 * 
 * This method processes excavation SVU (Stratigraphic Volume Unit) data and creates:
 * 1. Timeline entities for SVUs with dating information
 * 2. Time instant entities for beginning and end dates of timelines
 * 
 * The method generates proper RDF triples using time ontology for temporal relationships
 * and handles both BC and AD dates with appropriate formatting for xsd:gYear representation.
 * 
 * @param string &$ttl Reference to the TTL string where generated triples will be appended
 * @param array $excavationData Array containing excavation data with 'entities' and 'svus' keys
 * @param string $baseUri Base URI to use for generating timeline and instant URIs
 * @return void
 */
private function generateTimelineAndInstantSections(&$ttl, $excavationData, $baseUri) {
    if (empty($excavationData['entities']['svus'])) {
        return;
    }
    
    $timelineUris = [];
    $instantUris = [];
    
    // Collect all timeline URIs first
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
                
                // Format year properly for xsd:gYear
                $yearValue = abs((int)$year);
                $yearFormatted = str_pad($yearValue, 4, '0', STR_PAD_LEFT);
                
      
                
                $ttl .= "    time:inXSDgYear \"$yearFormatted\"^^xsd:gYear .\n\n";
            }
        }
    }
}



private function generateArchaeologistTtl($archaeologistUri, $archaeologistData)
{
    $ttl = "<$archaeologistUri> a excav:Archaeologist ;\n";
    
    if (!empty($archaeologistData['name'])) {
        $ttl .= "    foaf:name \"" . $archaeologistData['name'] . "\"^^xsd:literal ;\n";
    }
    
    if (!empty($archaeologistData['orcid'])) {
        // Format ORCID as full URL (like file uploads)
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
private function generateSquareTtl($squareUri, $square)
{
    $ttl = "<$squareUri> a excav:Square ;\n";
    $ttl .= "    dct:identifier \"" . $square['square_id'] . "\"^^xsd:literal ;\n";
    
    // Use proper coordinate field names to match file uploads
    if (!empty($square['square_east_west'])) {
        $ttl .= "    geo:lat \"" . $square['square_east_west'] . "\"^^xsd:decimal ;\n";
    }
    
    if (!empty($square['square_north_south'])) {
        $ttl .= "    geo:long \"" . $square['square_north_south'] . "\"^^xsd:decimal ;\n";
    }
    
    $ttl .= "    .\n\n";
    
    return $ttl;
}




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

/**
 * FIXED: Get the SVU identifier specifically from an Omeka item
 */
private function getSvuIdentifierFromOmekaItem($itemId) {
    try {
        error_log("Looking up SVU identifier for Omeka item ID: $itemId", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        
        $item = $this->api()->read('items', $itemId)->getContent();
        
        // Strategy 1: Try to get the dcterms:identifier value specifically
        $values = $item->values();
        
        if (isset($values['dcterms:identifier'])) {
            foreach ($values['dcterms:identifier'] as $value) {
                if ($value instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $identifier = $value->value();
                    error_log("Found dcterms:identifier for SVU item $itemId: $identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
                    return $identifier;
                }
            }
        }
        
        // Strategy 2: Extract from title looking for SVU-specific patterns ONLY
        $title = $item->displayTitle();
        error_log("SVU item $itemId title: $title", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        
        // Look for SVU-specific patterns only
        $svuPatterns = [
            '/\b(Layer-\d+)\b/',          // Layer-XX pattern
            '/\b(CV-\d+-\d+)\b/',         // CV-XXX-X pattern  
            '/\b(SVU-\d+)\b/',            // SVU-XX pattern
            '/\b(svu-\d+)\b/',            // svu-XX pattern
            '/\b(SU-\d+)\b/',             // SU-XX pattern
        ];
        
        foreach ($svuPatterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                error_log("Extracted SVU identifier from title: {$matches[1]}", 3, OMEKA_PATH . '/logs/identifier-debug.log');
                return $matches[1];
            }
        }
        
        // Strategy 3: Check resource class to confirm this is an SVU, then generate appropriate ID
        $resourceClass = $item->resourceClass();
        if ($resourceClass) {
            $className = strtolower($resourceClass->label());
            error_log("Resource class for item $itemId: $className", 3, OMEKA_PATH . '/logs/identifier-debug.log');
            
            if (strpos($className, 'stratigraphic') !== false || 
                strpos($className, 'svu') !== false || 
                strpos($className, 'unit') !== false) {
                
                // Generate SVU-appropriate identifier
                $identifier = "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT);
                error_log("Generated SVU identifier based on class: $identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
                return $identifier;
            }
        }
        
        // Strategy 4: Look for any numeric pattern and assume it's a layer
        if (preg_match('/\b(\d+)\b/', $title, $matches)) {
            $identifier = "Layer-" . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            error_log("Generated Layer identifier from numeric pattern: $identifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
            return $identifier;
        }
        
        // Final fallback for SVU
        $fallbackIdentifier = "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT);
        error_log("Using SVU fallback identifier: $fallbackIdentifier", 3, OMEKA_PATH . '/logs/identifier-debug.log');
        return $fallbackIdentifier;
        
    } catch (\Exception $e) {
        error_log("Error looking up SVU item $itemId: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/identifier-debug.log');
        return "Layer-" . str_pad($itemId % 100, 2, '0', STR_PAD_LEFT);
    }
}

private function processArchaeologicalContextSelections($formData, $itemSetId, $baseUri)
{
    $linkedResources = [];
    $declarations = []; 
    $existingDeclarations = []; // Initialize this variable
    
    error_log('=== PROCESSING CONTEXT SELECTIONS ===', 3, OMEKA_PATH . '/logs/context-debug.log');
    
    // Get excavation identifier for consistent URIs
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId) ?: "excavation";
    
    // UPDATED: Use a consistent base URI pattern for all references
    $excavationBaseUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier";

    $realLocationUri = $this->getRealLocationUriFromExcavation($itemSetId);
    if ($realLocationUri && empty($existingDeclarations['location'])) {
        // Initialize encounterDefinition variable
        $encounterDefinition = "";
        
        // Query the graph for the actual location data
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
            
            // Also add type declarations for referenced entities
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
        
        // Extract location ID from URI for declaration
        if (preg_match('/\/location\/([^\/]+)$/', $realLocationUri, $matches)) {
            $locationId = $matches[1];
            $declarations['location'] = [
                'uri' => $realLocationUri,
                'id' => $locationId
            ];
        }
    }
    error_log("Using base URI for references: $excavationBaseUri", 3, OMEKA_PATH . '/logs/context-debug.log');
    
    // Process selected square
    if (!empty($formData['selected_square'])) {
        $squareItemId = $formData['selected_square'];
        error_log("Processing selected square: $squareItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
        
        // FIXED: Get the real identifier from the Omeka item properly
        $realSquareId = $this->getRealIdentifierFromOmekaItem($squareItemId);
        if ($realSquareId) {
            $squareUri = "$excavationBaseUri/square/$realSquareId";
            $linkedResources['excav:foundInSquare'] = $squareUri;
            
            // Add declaration for later inclusion
            $declarations['square'] = [
                'uri' => $squareUri,
                'id' => $realSquareId
            ];
            
            error_log("✓ Linked to square: $squareUri (real ID: $realSquareId)", 3, OMEKA_PATH . '/logs/context-debug.log');
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
            
            error_log("✓ Linked to context: $contextUri (real ID: $realContextId)", 3, OMEKA_PATH . '/logs/context-debug.log');
        }
    }
    
    // FIXED: Process selected SVU with proper identifier extraction
    if (!empty($formData['selected_svu'])) {
        $svuItemId = $formData['selected_svu'];
        error_log("Processing selected SVU item ID: $svuItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
        
        // Get the SVU identifier specifically - not the excavation identifier
        $realSvuId = $this->getSvuIdentifierFromOmekaItem($svuItemId);
        error_log("Extracted SVU identifier: $realSvuId", 3, OMEKA_PATH . '/logs/context-debug-l.log');
        if ($realSvuId) {
            $svuUri = "$excavationBaseUri/svu/$realSvuId";
            $linkedResources['excav:foundInSVU'] = $svuUri;
            
            // Add declaration
            $declarations['svu'] = [
                'uri' => $svuUri,
                'id' => $realSvuId
            ];
            
            error_log("✓ Linked to SVU: $svuUri (real ID: $realSvuId)", 3, OMEKA_PATH . '/logs/context-debug.log');
        } else {
            error_log("✗ Could not extract SVU identifier from item $svuItemId", 3, OMEKA_PATH . '/logs/context-debug.log');
        }
    }
    
    // CRITICAL: Add excavation and location references with the same pattern
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
    
    error_log("✓ Added excavation reference: $excavationBaseUri", 3, OMEKA_PATH . '/logs/context-debug.log');
    error_log("✓ Added location reference: $locationUri", 3, OMEKA_PATH . '/logs/context-debug.log');
    
    return [
        'references' => $linkedResources, 
        'declarations' => $declarations
    ];
}

private function sanitizeFilenameForUri($filename) {
    // Get the file extension
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    
    // Remove or replace invalid characters in the basename
    $basename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $basename);
    $basename = preg_replace('/-+/', '-', $basename); // Replace multiple hyphens with a single one
    $basename = trim($basename, '-');
    
    // If empty basename, use a default name
    if (empty($basename)) {
        $basename = 'file';
    }
    
    // Combine with extension if it exists
    if (!empty($extension)) {
        return $basename . '.' . $extension;
    }
    
    return $basename;
}

private function processArrowheadFormData($formData, $itemSetId)
{
    // Debug log the incoming form data
    error_log('=== FORM PROCESSING DEBUG ===', 3, OMEKA_PATH . '/logs/form-debug.log');
    error_log('Form data received: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/form-debug.log');
    

    
    // Generate a unique ID for the arrowhead if not provided
    $arrowheadId = !empty($formData['arrowhead_identifier']) 
        ? $formData['arrowhead_identifier'] 
        : 'AH-' . uniqid();
    
    // Generate a base URI for resources
    $baseUri = "http://localhost/megalod/$itemSetId/item/$arrowheadId";
    // Create resource URIs
    $arrowheadUri = $baseUri;
    $morphologyUri = "$baseUri/morphology/$arrowheadId";
    $chippingUri = "$baseUri/chipping/$arrowheadId";
    $excavationUri = "$baseUri";
    $encounterUri = "$baseUri/encounter/$arrowheadId";
    //gps uri 
    $gpsUri = "$baseUri/gps/$arrowheadId";
    
    // Build TTL data
    $ttl = $this->getTtlPrefixes();
    
    // ENHANCED: Process selected archaeological context resources
    $contextResult = $this->processArchaeologicalContextSelections($formData, $itemSetId, $baseUri);
    $linkedResources = $contextResult['references'];
    $resourceDeclarations = $contextResult['declarations'];
    
    $ttl .= "<$arrowheadUri> a ah:Arrowhead, excav:Item;\n";
    $ttl .= "    dct:identifier \"$arrowheadId\"^^xsd:literal;\n";
    // Add square reference if selected
    if (!empty($formData['selected_square'])) {
    $squareItemId = $formData['selected_square'];
    $realSquareId = $this->getRealIdentifierFromOmekaItem($squareItemId);
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId) ?: "excavation";
    if ($realSquareId) {
        $squareUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/$realSquareId";
        $ttl .= "    excav:foundInSquare <$squareUri>;\n";
        error_log("Added square reference: $squareUri", 3, OMEKA_PATH . '/logs/form-debug.log');
    }
}
    
    error_log("Arrowhead URI: $excavationUri", 3, OMEKA_PATH . '/logs/form.log');

    $locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
if ($locationUri) {
    $ttl .= "    excav:foundInLocation <$locationUri>;\n";
    error_log("✓ Added real location URI: $locationUri", 3, OMEKA_PATH . '/logs/form-debug.log');
} else {
    error_log("⚠ No real location found - skipping location reference", 3, OMEKA_PATH . '/logs/form-debug.log');
}    
    // CRITICAL FIX: Add ALL context references to the arrowhead item

    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
if ($excavationIdentifier) {
    $excavationUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier";
    $ttl .= "    excav:foundInExcavation <$excavationUri>;\n";
    error_log("Added excavation reference: $excavationUri", 3, OMEKA_PATH . '/logs/form-debug.log');
}

// Also update the location reference section to ONLY add location if no context selections exist:
$locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
if ($locationUri && empty($formData['selected_square']) && empty($formData['selected_context']) && empty($formData['selected_svu'])) {
    $ttl .= "    excav:foundInLocation <$locationUri>;\n";
    error_log("✓ Added basic location URI (no specific context): $locationUri", 3, OMEKA_PATH . '/logs/form-debug.log');
} else {
    error_log("⚠ Skipping location reference - context selections exist or no location found", 3, OMEKA_PATH . '/logs/form-debug.log');
}

    
    // Add annotation if provided
    if (!empty($formData['arrowhead_annotation'])) {
        $ttl .= "    dbo:Annotation \"" . $formData['arrowhead_annotation'] . "\"^^xsd:literal;\n";
    }
    
    // Add condition state
    if (!empty($formData['condition_state'])) {
        $value = (stripos($formData['condition_state'], 'true') !== false) ? "true" : "false";
        $ttl .= "    crm:E3_Condition_State \"$value\"^^xsd:boolean;\n";
    }
    
    // Add type (elongate/short)
    if (!empty($formData['arrowhead_type'])) {
        $value = (stripos($formData['arrowhead_type'], 'true') !== false) ? "true" : "false";
        $ttl .= "    crm:E55_Type \"$value\"^^xsd:boolean;\n";
    }
    
    // FIXED: Add elongation index with correct KOS namespace
    if (!empty($formData['elongation_index'])) {
        $ttl .= "    excav:elongationIndex <https://purl.org/megalod/kos/MegaLOD-IndexElongation/" . $formData['elongation_index'] . ">;\n";
    }

    // thickness index
    if (!empty($formData['thickness_index'])) {
        $ttl .= "    excav:thicknessIndex <https://purl.org/megalod/kos/MegaLOD-IndexThickness/" . $formData['thickness_index'] . ">;\n";
    }
    
    // Add material
    if (!empty($formData['arrowhead_material'])) {
        $ttl .= "    crm:E57_Material <" . $formData['arrowhead_material'] . ">;\n";
    }
    
    // FIXED: Add shape with correct KOS namespace
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
    
    // FIXED: Add variant with correct KOS namespace
    if (!empty($formData['arrowhead_variant'])) {
        $variantSafe = strtolower($formData['arrowhead_variant']);
        $ttl .= "    ah:variant <https://purl.org/megalod/kos/ah-variant/$variantSafe>;\n";
    }

    // process gps coordinates lat and long
    if (!empty($formData['gps_latitude']) && !empty($formData['gps_longitude'])) {
        $gpsUri = "$baseUri/gps/$arrowheadId";
        $ttl .= "    excav:hasGPSCoordinates <$gpsUri>;\n";
    }

    // add encounter_date
    if (!empty($formData['encounter_date'])) {
        $ttl .= "    dct:date \"" . $formData['encounter_date'] . "\"^^xsd:literal;\n";
    }
    

    // Process measurements...
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

    // Add thickness, body length, base length processing...
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
    
    // Add chipping information
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
    
    // Process coordinates
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
    
    // Add images/web resources
    if (!empty($formData['images'])) {
    $images = $formData['images'];
    if (is_array($images)) {
        foreach ($images as $image) {
            if (!empty($image)) {
                // Sanitize the image filename for URI use
                $imageParts = parse_url($image);
                if (isset($imageParts['path'])) {
                    $originalFilename = basename($imageParts['path']);
                    $sanitizedFilename = $this->sanitizeFilenameForUri($originalFilename);
                    
                    // Replace the original filename in the URL with the sanitized one
                    $imageUri = str_replace($originalFilename, $sanitizedFilename, $image);
                    $ttl .= "    edm:Webresource <$imageUri>;\n";
                } else {
                    $ttl .= "    edm:Webresource <$image>;\n";
                }
            }
        }
    } else if (!empty($images)) {
        // Handle single image case
        $imageParts = parse_url($images);
        if (isset($imageParts['path'])) {
            $originalFilename = basename($imageParts['path']);
            $sanitizedFilename = $this->sanitizeFilenameForUri($originalFilename);
            
            // Replace the original filename in the URL with the sanitized one
            $imageUri = str_replace($originalFilename, $sanitizedFilename, $images);
            $ttl .= "    edm:Webresource <$imageUri>;\n";
        } else {
            $ttl .= "    edm:Webresource <$images>;\n";
        }
    }
}
    
    // Close the main arrowhead resource
    $ttl .= "    .\n\n";

    // Add entity declarations for referenced resources
    $ttl .= "\n# =========== RESOURCE DECLARATIONS ===========\n\n";
    
    // Excavation declaration
    $excavationIdentifier = $this->getExcavationIdentifierFromItemSet($itemSetId);
    if ($excavationIdentifier) {
        $excavationUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier";
        $ttl .= "<$excavationUri> a excav:Excavation ;\n";
        $ttl .= "    dct:identifier \"$excavationIdentifier\"^^xsd:literal .\n\n";
        error_log("Added excavation declaration: $excavationUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
    }
    
    // Location declaration (CRITICAL FIX for SHACL validation)

// Location declaration (CRITICAL FIX for SHACL validation)
$locationUri = $this->getRealLocationUriFromExcavation($itemSetId);
error_log("Location URI from excavation: $locationUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
if ($locationUri) {
    // Try to get real location data from excavation
    $locationData = $this->getLocationDataFromExcavation($itemSetId);
    error_log("Location data: " . print_r($locationData, true), 3, OMEKA_PATH . '/logs/ttl-fixes.log');
    
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
    error_log("Added location declaration: $locationUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
    // REMOVED: Don't add dct:identifier for locations
    $ttl = rtrim($ttl, " ;\n") . " .\n\n";
}
    
    // Add declarations for any other referenced resources (context, square, SVU)
    if (!empty($formData['selected_square'])) {
        $squareItemId = $formData['selected_square'];
        $realSquareId = $this->getRealIdentifierFromOmekaItem($squareItemId);
        if ($realSquareId) {
            $squareUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/square/$realSquareId";
            $ttl .= "<$squareUri> a excav:Square ;\n";
            $ttl .= "    dct:identifier \"$realSquareId\"^^xsd:literal .\n\n";
            error_log("Added square declaration: $squareUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
        }
    }
    
    if (!empty($formData['selected_context'])) {
        $contextItemId = $formData['selected_context'];
        $realContextId = $this->getRealIdentifierFromOmekaItem($contextItemId);
        if ($realContextId) {
            $contextUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/context/$realContextId";
            $ttl .= "<$contextUri> a excav:Context ;\n";
            $ttl .= "    dct:identifier \"$realContextId\"^^xsd:literal .\n\n";
            error_log("Added context declaration: $contextUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
        }
    }
    
    if (!empty($formData['selected_svu'])) {
        $svuItemId = $formData['selected_svu'];
        $realSvuId = $this->getRealIdentifierFromOmekaItem($svuItemId);
        error_log("Processing selected SVU item ID: $svuItemId", 3, OMEKA_PATH . '/logs/ttl-fixesssss.log');
        if ($realSvuId) {
            $svuUri = "http://localhost/megalod/$itemSetId/excavation/$excavationIdentifier/svu/$realSvuId";
            $ttl .= "<$svuUri> a excav:StratigraphicVolumeUnit ;\n";
            $ttl .= "    dct:identifier \"$realSvuId\"^^xsd:literal .\n\n";
            error_log("Added SVU declaration: $svuUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
        }
    }

    // Add coordinates block if present
    if ($coordinatesData) {
        $ttl .= "<{$coordinatesData['uri']}> a excav:Coordinates;\n";
        $ttl .= "    schema:value \"{$coordinatesData['x']} {$coordinatesData['x_unit']}\"^^xsd:literal;\n";
        $ttl .= "    schema:value \"{$coordinatesData['y']} {$coordinatesData['y_unit']}\"^^xsd:literal;\n";
        
        if ($coordinatesData['z']) {
            $ttl .= "    schema:value \"{$coordinatesData['z']} {$coordinatesData['z_unit']}\"^^xsd:literal;\n";
        }
        
        $ttl .= "    .\n\n";
    }

    // Add GPS coordinates if available
    if(!empty($formData['gps_latitude']) && !empty($formData['gps_longitude'])) {
        $ttl .= "<$gpsUri> a excav:GPSCoordinates;\n";
        $ttl .= "    geo:lat \"" . $formData['gps_latitude'] . "\"^^xsd:decimal;\n";
        $ttl .= "    geo:long \"" . $formData['gps_longitude'] . "\"^^xsd:decimal;\n";
        $ttl .= "    .\n\n";
    }

    // Add morphology
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
    
    // Add chipping details if necessary
    if ($hasChippingData) {
        $ttl .= "<$chippingUri> a ah:Chipping;\n";
        
        // FIXED: All chipping properties with correct KOS namespace
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
    /*
    // FIXED: Add encounter event with ALL context references
    $ttl .= "<$encounterUri> a excav:EncounterEvent;\n";
    $ttl .= "    dct:date \"" . date('Y-m-d') . "\"^^xsd:literal;\n";
    $ttl .= "    crmsci:O19_encountered_object <$arrowheadUri>;\n";
    $ttl .= "    excav:foundInExcavation <$excavationUri>;\n"; 

    // CRITICAL FIX: Add ALL selected context references to encounter event
    foreach ($linkedResources as $property => $resourceUri) {
        $ttl .= "    $property <$resourceUri>;\n";
        error_log("Added $property to encounter event: $resourceUri", 3, OMEKA_PATH . '/logs/form-debug.log');
    }
    
    $ttl .= "    .\n\n";*/
    
    // Add all measurement resource blocks
    $ttl .= $measurementBlocks;
    
    /* FIXED: Add proper location declaration to satisfy SHACL
    if (strpos($ttl, 'excav:foundInLocation') !== false) {
        $ttl .= "<$locationUri> a excav:Location;\n";
        $ttl .= "    rdfs:label \"Excavation Location\"^^xsd:string;\n";
        $ttl .= "    .\n\n";
        
        error_log("Added location type declaration for $locationUri", 3, OMEKA_PATH . '/logs/ttl-fixes.log');
    }
    */
    error_log('FINAL TTL GENERATED: ' . $ttl, 3, OMEKA_PATH . '/logs/final-ttl-debug.log');    

    return $ttl;
}



private function updateItemSetWithExcavationInfo($itemSetId, $excavationData) {
    if (!$itemSetId || empty($excavationData)) {
        return false;
    }
    
    try {
        // Prepare the update data
        $updateData = [];
        
        // Add description if location is available
        if (!empty($excavationData['location'])) {
            $updateData['dcterms:description'] = [
                [
                    'type' => 'literal',
                    'property_id' => 4,
                    '@value' => "Archaeological excavation at " . $excavationData['location']
                ]
            ];
        }
        
        // Add archaeologist as creator if available
        if (!empty($excavationData['archaeologist'])) {
            $updateData['dcterms:creator'] = [
                [
                    'type' => 'literal',
                    'property_id' => 7, // Dublin Core Creator
                    '@value' => $excavationData['archaeologist']
                ]
            ];
        }
        
        // Execute the update if we have data to update
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
        error_log('Failed to update item set with excavation info: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-update.log');
        return false;
    }
    
    return true;
}



private function uploadTtlDataWithMedia($ttlData, $itemSetId, $uploadedFiles) {
    // Store files temporarily
    $this->uploadedFiles = $uploadedFiles;
    
    // Call the regular upload method
    return $this->uploadTtlData($ttlData, $itemSetId);
}

public function processCollectingFormAction()
{
    $redirect = $this->requireLogin();
    if ($redirect) return $redirect;
    // Get the item set ID and upload type from query parameters
    $itemSetId = $this->params()->fromQuery('item_set_id');
    $uploadType = $this->params()->fromQuery('upload_type', 'arrowhead');
    
    // Get all POST data from the collecting form
    $formData = $this->params()->fromPost();
    
    error_log('Received collecting form data: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/collecting-form.log');
    
    // STORE UPLOADED FILES IMMEDIATELY
    $uploadedFiles = null;
    if (isset($_FILES['file']['54'])) {
        $uploadedFiles = $_FILES['file']['54'];
        error_log('Found uploaded files: ' . print_r($uploadedFiles, true), 3, OMEKA_PATH . '/logs/collecting-form.log');
    }
    
    // Transform collecting form data to format expected by processArrowheadFormData
    $arrowheadData = $this->transformCollectingFormToArrowheadData($formData);
    error_log('Transformed arrowhead data: ' . print_r($arrowheadData, true), 3, OMEKA_PATH . '/logs/property-debug.log');

    
// Process the transformed data
if (!empty($arrowheadData)) {
    $ttlData = $this->processArrowheadFormData($arrowheadData, $itemSetId);
    // MODIFY THIS LINE - pass the uploaded files
    $result = $this->uploadTtlDataWithMedia($ttlData, $itemSetId, $uploadedFiles);        
    error_log('Processed collecting form data: ' . $result, 3, OMEKA_PATH . '/logs/collecting-form.log');
    
    // Redirect back to excavation context with success message
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

// If transformation failed, redirect with error
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



private function transformCollectingFormToArrowheadData($formData)
{
    error_log('RAW COLLECTING FORM DATA: ' . print_r($formData, true), 3, OMEKA_PATH . '/logs/debug-all-fields.log');

    $arrowheadData = [];
    
    // Updated field mappings based on your actual form structure
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
    
    // Process the mapping
// In transformCollectingFormToArrowheadData method, add this logic:

foreach ($fieldMappings as $collectingField => $arrowheadField) {
    if (isset($formData[$collectingField]) && !empty($formData[$collectingField])) {
        $value = $formData[$collectingField];
        
        // Enhanced boolean processing
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
    // These come from the form template's archaeological context section
    if (!empty($formData['selected_square'])) {
        $arrowheadData['selected_square'] = $formData['selected_square'];
        error_log('Added selected square: ' . $formData['selected_square'], 3, OMEKA_PATH . '/logs/context-debug.log');
    }
    
    if (!empty($formData['selected_context'])) {
        $arrowheadData['selected_context'] = $formData['selected_context'];
        error_log('Added selected context: ' . $formData['selected_context'], 3, OMEKA_PATH . '/logs/context-debug.log');
    }
    
    if (!empty($formData['selected_svu'])) {
        $arrowheadData['selected_svu'] = $formData['selected_svu'];
        error_log('Added selected SVU: ' . $formData['selected_svu'], 3, OMEKA_PATH . '/logs/context-debug.log');
    }

    // Handle file uploads for images
    if (isset($formData['file']['54']) && is_array($formData['file']['54'])) {
        $imageFiles = $formData['file']['54'];
        error_log('Image files: ' . print_r($imageFiles, true), 3, OMEKA_PATH . '/logs/image-files.log');
        $imageUrls = [];
        
        foreach ($imageFiles as $imageFile) {
            if (!empty($imageFile)) {
                // Process uploaded file and create URL
                // For now, we'll create a placeholder URL structure
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
    
    // If we have an item set ID, try to find and add the location from the excavation
    if ($itemSetId) {
        try {
            // FIXED: Use the item set ID directly for the location URI
            $locationUri = $this->getExcavationLocationUri(null, $itemSetId);
            
            if ($locationUri) {
                error_log("Using location for item set $itemSetId: $locationUri", 3, OMEKA_PATH . '/logs/location-debug.log');
                $arrowheadData['location'] = $locationUri;
            }
        } catch (\Exception $e) {
            error_log('Error setting excavation location: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/location-debug.log');
        }
    }

    // Remove unit fields if their corresponding value fields are empty
    $valuesToCheck = [
        'thickness' => 'thickness_unit',
        'body_length' => 'body_length_unit', 
        'base_length' => 'base_length_unit'
    ];
    
    foreach ($valuesToCheck as $valueField => $unitField) {
        if (empty($arrowheadData[$valueField]) && isset($arrowheadData[$unitField])) {
            unset($arrowheadData[$unitField]);
            error_log("Removed orphaned unit field: $unitField", 3, OMEKA_PATH . '/logs/debug-all-fields.log');
        }
    }

    // Log the transformed data for debugging
    error_log('TRANSFORMED ARROWHEAD DATA: ' . print_r($arrowheadData, true), 3, OMEKA_PATH . '/logs/debug-all-fields.log');

    return $arrowheadData;
}

private function getExcavationLocationUri($excavationId, $itemSetId = null) {
    // FIXED: Use itemSetId if available, otherwise use excavationId
    $baseId = $itemSetId ?: $excavationId;
    return "http://localhost/megalod/$baseId/location/excavation-location";
}


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

    error_log('File type: ' . $fileType, 3, OMEKA_PATH . '/logs/file-upload.log');

    try {
        if (!isset($_FILES['file']) || empty($_FILES['file']['tmp_name'])) {
            error_log('No file uploaded', 3, OMEKA_PATH . '/logs/file-upload.log');
        return 'Error: No file uploaded';
    }
        if ($fileType === 'application/xml' || $fileType === 'text/xml') {
            $rdfXmlData = $this->xmlParser($file);
            if (is_string($rdfXmlData) && strpos($rdfXmlData, 'Failed') === false) {
                $ttlData = $this->xmlTtlConverter($rdfXmlData);
                error_log('ttl data: ' . $ttlData, 3, OMEKA_PATH . '/logs/file-upload.log');
            } else {
                throw new \Exception('Failed to process XML file: ' . $rdfXmlData);
            }
        } else {
            $ttlData = file_get_contents($file['tmp_name']);
        }

        error_log('File tmp_name: ' . $file['tmp_name'], 3, OMEKA_PATH . '/logs/file-upload.log');
        error_log('File exists check: ' . (file_exists($file['tmp_name']) ? 'exists' : 'does not exist'), 3, OMEKA_PATH . '/logs/file-upload.log');

        // Skip validation if not explicitly required - for continuous uploads
        // to avoid unnecessary error messages
        if ($uploadType) {
            try {
                $this->validateUploadType($ttlData, $uploadType);
                error_log('Upload type validation passed for: ' . $uploadType . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');


            } catch (\Exception $e) {
                // Type mismatch but not critical for continuous upload
                error_log('Upload type validation warning: ' . $e->getMessage());
            }
        }
        error_log('TTL data: ' . $ttlData, 3, OMEKA_PATH . '/logs/file-upload.log');
        error_log('sending to uploadTtlData', 3, OMEKA_PATH . '/logs/file-upload.log');

        $result = $this->uploadTtlData($ttlData, $itemSetId);
        return $result;
    } catch (\Exception $e) {
        return 'Error processing file: ' . $e->getMessage();
    }
}

    


private function uploadTtlData(string $ttlData, ?int $itemSetId = null): string {
    error_log("=== UPLOAD TTL DATA DEBUG START ===", 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("Item Set ID: " . ($itemSetId ?: 'none'), 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("TTL Data Length: " . strlen($ttlData), 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("TTL Data Sample (first 1000 chars): " . substr($ttlData, 0, 1000), 3, OMEKA_PATH . '/logs/upload-debug.log');
    error_log("itemsetid: " . ($itemSetId ?: 'none'), 3, OMEKA_PATH . '/logs/hkjfhkj-debug.log');
    // Set the current processing context
    $this->currentProcessingItemSetId = $itemSetId;
    
    try {
        // Check if this is excavation data
        error_log('=== CHECKING IF EXCAVATION DATA ===', 3, OMEKA_PATH . '/logs/upload-debug.log');
        $isExcavation = false;
        $excavationIdentifier = "0"; // Default to "0" graph

        try {
            $this->validateUploadType($ttlData, 'excavation');
            error_log('✓ Excavation validation passed', 3, OMEKA_PATH . '/logs/upload-debug.log');
            $isExcavation = true;

            error_log('This is excavation data' . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');

            // Extract excavation identifier for graph organization
            $extractedId = $this->extractExcavationIdentifier($ttlData);
            error_log('Extracted excavation identifier: ' . $extractedId . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
            if ($extractedId) {
                $excavationIdentifier = $extractedId;
                error_log('✓ Extracted excavation identifier: ' . $excavationIdentifier ."\n", 3, OMEKA_PATH . '/logs/malfunction.log');
            } else {
                error_log('⚠ Could not extract excavation identifier', 3, OMEKA_PATH . '/logs/upload-debug.log');
            }
        } catch (\Exception $e) {
            error_log('❌ Excavation validation failed: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/upload-debug.log');
            
            // Check if this item belongs to an excavation item set
            if ($itemSetId) {
                error_log('Item set ID provided: ' . $itemSetId, 3, OMEKA_PATH . '/logs/upload-debug.log');
                $excavationId = $this->getExcavationIdentifierFromItemSet($itemSetId);
                if ($excavationId) {
                    $excavationIdentifier = $excavationId;
                    error_log('Using excavation ID from item set: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/upload-debug.log');
                }
            }
        }

        error_log('so far so good', 3, OMEKA_PATH . '/logs/malfunction.log');

        error_log('Final determination - isExcavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/upload-debug.log');
        error_log('Excavation identifier: ' . $excavationIdentifier, 3, OMEKA_PATH . '/logs/upload-debug.log');


        // Normalize URIs based on context
        if ($itemSetId) {
            error_log('Normalizing URIs for item set ID: ' . $itemSetId . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
            $ttlData = $this->normalizeUris($ttlData, $itemSetId);
            error_log('URIs normalized for item set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/uri-normalize.log');
        } elseif ($isExcavation && $excavationIdentifier) {
            // SINGLE POINT OF ITEM SET CREATION FOR EXCAVATIONS
            error_log('Normalizing URIs for excavation identifier: ' . $excavationIdentifier . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
            if ($this->excavationIdentifierExists($excavationIdentifier)) {
                // Optionally handle duplicate excavation identifiers
                // For now, we'll proceed but log a warning
                error_log('Warning: Excavation identifier already exists: ' . $excavationIdentifier . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
            }
            
            // Extract excavation metadata
            $excavationMetadata = $this->extractExcavationMetadataFromTtl($ttlData);
            
            try {
                // Create item set with proper metadata - SINGLE CREATION POINT
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
                
                // If successful, get the new item set ID
                if ($response) {
                    $newItemSet = $response->getContent();
                    $itemSetId = $newItemSet->id();
                    
                    error_log('Successfully created single item set with ID: ' . $itemSetId . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
                    
                    // Update the processing context with the new item set ID
                    $this->currentProcessingItemSetId = $itemSetId;
                    error_log('Updated current processing item set ID to: ' . $this->currentProcessingItemSetId . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
                    
                    // Now normalize the URIs with the new item set ID
                    $ttlData = $this->normalizeUris($ttlData, $itemSetId);
                    error_log('normalized data: ' . $ttlData . "\n", 3, OMEKA_PATH . '/logs/malfunction-normalize.log');
                    
                    // Store the mapping between item set and excavation
                    $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationIdentifier);
                }
            } catch (\Exception $e) {
                error_log('Error creating item set for excavation: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
                return 'Error: Failed to create excavation item set - ' . $e->getMessage();
            }
        }
        error_log('New TTL data for excavation: ' . $ttlData, 3, OMEKA_PATH . '/logs/ttlttl-debug.log');

        error_log('is excavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/excavation-debug.log');

        // deal with encounter validation for arrowheads
        $isArrowhead = strpos($ttlData, 'ah:Arrowhead') !== false || strpos($ttlData, 'excav:Item') !== false;
        error_log('is arrowhead: ' . ($isArrowhead ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/arrowhead-debug.log');
        error_log('isitemSetId: ' . ($itemSetId ? $itemSetId : 'none'), 3, OMEKA_PATH . '/logs/arrowhead-debug.log');
        error_log('isExcavation: ' . ($isExcavation ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/arrowhead-debug.log');
        if ($isArrowhead && $itemSetId) {
            error_log('=== APPLYING ENCOUNTER VALIDATION FOR ARROWHEAD ===', 3, OMEKA_PATH . '/logs/encounter-validation.log');
            error_log('Item Set ID: ' . $itemSetId, 3, OMEKA_PATH . '/logs/encounter-validation.log');
            // 1. Extract arrowhead context references from TTL
            // log ttl data
            error_log('Extracting arrowhead context from TTL data', 3, OMEKA_PATH . '/logs/encounter-validation.log');
            error_log('TTL data: ' . $ttlData, 3, OMEKA_PATH . '/logs/encounter-validation-kkkk.log');
            $arrowheadContext = $this->extractArrowheadContextFromTtl($ttlData);
            error_log('Extracted context: ' . print_r($arrowheadContext, true), 3, OMEKA_PATH . '/logs/encounter-validation.log');
            
            // 2. Validate context relationships exist in item set
            $validationResult = $this->validateContextRelationships($arrowheadContext, $itemSetId);            // log the validation result
            error_log('Validation result: ' . print_r($validationResult, true), 3, OMEKA_PATH . '/logs/encounter-validation.log');
            
            if (!$validationResult['valid']) {
                // Return validation error immediately
                $errorDetails = "\n\nValidation Details:\n" . json_encode($validationResult['details'], JSON_PRETTY_PRINT);
                return 'Validation Error: ' . $validationResult['error'] . $errorDetails;
            }
            
            error_log('✓ Context validation passed', 3, OMEKA_PATH . '/logs/encounter-validation.log');
            
            // 3. Find or create encounter event
            $encounterEvent = $this->findOrCreateEncounterEvent($arrowheadContext, $itemSetId);
            error_log('Encounter event: ' . print_r($encounterEvent, true), 3, OMEKA_PATH . '/logs/encounter-validation.log');
            
            // 4. Update TTL with encounter event reference
            $ttlData = $this->addEncounterEventToTtl($ttlData, $encounterEvent, $itemSetId);
            error_log('✓ Enhanced TTL with encounter event', 3, OMEKA_PATH . '/logs/encounter-validation.log');
            error_log('Final TTL after encounter event: ' . $ttlData, 3, OMEKA_PATH . '/logs/encounter-aux-aux.log');
        }

        //log the final TTL data before upload
        error_log('Final TTL data before upload: ' . $ttlData, 3, OMEKA_PATH . '/logs/final-ttl-debug.log');
        // Now proceed with the regular upload process
        // First, upload to GraphDB with the excavation identifier if available
        $graphDbResult = $this->sendToGraphDB($ttlData, $itemSetId);
        error_log('GraphDB upload result: ' . $graphDbResult, 3, OMEKA_PATH . '/logs/auxNew.log');
        
        if (strpos($graphDbResult, 'successfully') !== false) {
            // If GraphDB upload is successful, then process in Omeka S
            $omekaData = $this->transformTtlToOmekaSData($ttlData, $itemSetId);

            $omekaResponse = $this->sendToOmekaS($omekaData, $itemSetId);
            
            if (empty($omekaResponse['errors'])) {
                $createdItems = $omekaResponse['created_items'];
                $updatedCount = 0;
                
                foreach ($createdItems as $item) {
                    if (is_array($item) && isset($item['o:id'])) {
                        $itemId = $item['o:id']; // Get the Omeka assigned ID
                    }
                    else {
                        error_log('Invalid item structure: ' . print_r($item, true), 3, OMEKA_PATH . '/logs/invalid-item.log');
                        $itemId = null;
                    }
                    
                    // Update titles based on content type
                    if ($isExcavation) {
                        $title = "Excavation $excavationIdentifier Item $itemId";
                    } else {
                        $title = "Arrowhead $itemId" . ($excavationIdentifier ? " (Excavation $excavationIdentifier)" : "");
                    }
                    
                    // Update the title with the Omeka ID
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
                            error_log("Updated title for item $itemId: '$title'", 3, OMEKA_PATH . '/logs/excavation-debug.log');
                        }
                    } catch (\Exception $e) {
                        error_log('Error updating item title: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
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
        // Clear the processing context - this will always execute
        $this->currentProcessingItemSetId = null;
        error_log("Cleared processing context", 3, OMEKA_PATH . '/logs/context-debug.log');
    }
}


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


private function excavationIdentifierExists($excavationIdentifier) {
    if (empty($excavationIdentifier)) {
        return false;
    }
    
    try {
        // Search for items with the given excavation identifier
        $response = $this->api()->search('items', [
            'property' => [
                [
                    'property' => 10, // Assuming 10 is the property ID for dcterms:identifier
                    'type' => 'eq',
                    'text' => $excavationIdentifier
                ]
            ]
        ]);
        
        // If we found any items, the identifier exists
        return $response->getTotalResults() > 0;
        
    } catch (\Exception $e) {
        error_log('Error checking if excavation identifier exists: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-validation.log');
        return false; // Assume it doesn't exist in case of error
    }
}

private function storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationId)
{
    // Get existing mappings from site settings
    $mappings = $this->siteSettings()->get('excavation_itemset_mappings', []);
    
    // Add the new mapping
    $mappings[$itemSetId] = $excavationId;
    
    // Save the updated mappings
    $this->siteSettings()->set('excavation_itemset_mappings', $mappings);
    
    error_log('Stored mapping: Item Set ' . $itemSetId . ' -> Excavation ' . $excavationId, 3, OMEKA_PATH . '/logs/excavation-mappings.log');
}



private function extractExcavationIdentifier(string $ttlData): ?string {
    error_log('Extracting excavation identifier from TTL' . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
    
    // Pattern 1: Direct dct:identifier pattern from your TTL
    if (preg_match('/dct:identifier\s+"([^"]+)"\^\^xsd:literal/', $ttlData, $matches)) {
        error_log('Found identifier via dct:identifier: ' . $matches[1] . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
        return $matches[1];
    }
    
    // Pattern 2: Alternative dcterms:identifier
    if (preg_match('/dcterms:identifier\s+"([^"]+)"/', $ttlData, $matches)) {
        error_log('Found identifier via dcterms:identifier: ' . $matches[1] . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
        return $matches[1];
    }
    
    
    error_log('No excavation identifier found in TTL' . "\n", 3, OMEKA_PATH . '/logs/malfunction.log');
    return null;
}


private function validateUploadType(string $ttlData, ?string $uploadType): void
{
    if (!$uploadType) {
        return; // No upload type specified, skip validation
    }

    error_log('Validating upload type: ' . $uploadType, 3, OMEKA_PATH . '/logs/validation.log');
    error_log('TTL data sample: ' . substr($ttlData, 0, 500), 3, OMEKA_PATH . '/logs/validation.log');
    
    // UPDATED patterns to match your TTL file
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
            error_log("Found excavation pattern: $pattern", 3, OMEKA_PATH . '/logs/validation.log');
            break;
        }
    }
    
    $isArrowhead = false;
    foreach ($arrowheadPatterns as $pattern) {
        if (strpos($ttlData, $pattern) !== false) {
            $isArrowhead = true;
            error_log("Found arrowhead pattern: $pattern", 3, OMEKA_PATH . '/logs/validation.log');
            break;
        }
    }
    
    error_log("Validation results - isExcavation: " . ($isExcavation ? 'true' : 'false') . 
              ", isArrowhead: " . ($isArrowhead ? 'true' : 'false'), 3, OMEKA_PATH . '/logs/validation.log');
    
    if ($uploadType === 'excavation' && !$isExcavation) {
        // Check if it's actually an arrowhead being uploaded to an excavation context
        if ($isArrowhead) {
            error_log('Arrowhead data detected when expecting excavation, but this is allowed for item sets', 3, OMEKA_PATH . '/logs/validation.log');
            return; // Allow arrowheads to be added to excavation context
        }
        throw new \Exception('Invalid data type for excavation upload.');
    } elseif ($uploadType === 'arrowhead' && !$isArrowhead) {
        throw new \Exception('Invalid data type for Arrowhead upload.');
    }
    
    error_log('Data validation passed: ' . ($isExcavation ? 'Excavation' : 'Arrowhead'), 3, OMEKA_PATH . '/logs/validation.log');
}


public function xmlParser($file)
{
    error_log('Starting XML parsing with enhanced processor');
    
    // Determine which XSLT to use based on the file content
    $xmlContent = file_get_contents($file['tmp_name']);
    
    // More robust detection
    if (strpos($xmlContent, '<item id="AH') !== false || 
        strpos($xmlContent, 'arrowhead') !== false ||
        strpos($xmlContent, '<ah:') !== false) {
        $xsltPath = OMEKA_PATH . '/modules/AddTriplestore/asset/xlst/arrowXslt.xml'; // Arrowhead XSLT
        error_log('Detected arrowhead XML');
    } elseif (strpos($xmlContent, '<Excavation') !== false || 
              strpos($xmlContent, 'excavation') !== false ||
              strpos($xmlContent, '<excav:') !== false) {
        $xsltPath = OMEKA_PATH . '/modules/AddTriplestore/asset/xlst/excavationXslt.xml'; // Excavation XSLT
        error_log('Detected excavation XML');
    } else {
        error_log('Could not determine XML type for XSLT selection.');
        return 'Could not determine XML type';
    }

    // Validate XSLT file exists
    if (!file_exists($xsltPath)) {
        error_log('XSLT file not found: ' . $xsltPath);
        return 'XSLT file not found';
    }

    // Load XSLT file
    $xslt = new \DOMDocument();
    $xslt->load($xsltPath);

    // Load the uploaded XML file into a DOMDocument
    $xmlDoc = new \DOMDocument();
    if (!$xmlDoc->load($file['tmp_name'])) {
        error_log('Failed to load XML file');
        return 'Failed to load XML file';
    }

    // Apply XSLT transformation
    $processor = new \XSLTProcessor();
    
    $processor->importStylesheet($xslt);
    
    // FIXED: Namespace registration is handled in XSLT stylesheet instead
    
    // Transform to RDF-XML
    $rdfXmlConverted = $processor->transformToXML($xmlDoc);

    if (!$rdfXmlConverted) {
        error_log('Failed to convert XML to RDF-XML');
        return 'Failed to convert XML to RDF-XML';
    }

    error_log('Successfully converted XML to RDF-XML');
    return $rdfXmlConverted;
}



public function xmlTtlConverter($rdfXmlData)
{
    error_log('Converting RDF-XML to TTL with enhanced processing');
    
    // Log RDF-XML for debugging
    error_log('RDF-XML before processing: ' . substr($rdfXmlData, 0, 1000), 3, OMEKA_PATH . '/logs/namespace-debug.log');
    
    // ADDED: Explicitly register Dublin Core Terms namespace
    \EasyRdf\RdfNamespace::set('dct', 'http://purl.org/dc/terms/');
    \EasyRdf\RdfNamespace::set('ah', 'https://purl.org/megalod/ms/ah/');
    \EasyRdf\RdfNamespace::set('excav', 'https://purl.org/megalod/ms/excavation/');
    \EasyRdf\RdfNamespace::set('dct', 'http://purl.org/dc/terms/');
    \EasyRdf\RdfNamespace::set('dul', 'http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#');
    // Clean the RDF-XML first
    $cleanedRdfXml = $this->cleanRdfXmlNamespaces($rdfXmlData);
    error_log('RDF-XML after cleaning: ' . substr($cleanedRdfXml, 0, 1000), 3, OMEKA_PATH . '/logs/namespace-debug.log');
    
    $rdfGraph = new \EasyRdf\Graph();
    $rdfGraph->parse($cleanedRdfXml, 'rdfxml');

    error_log('RDF-XML data loaded into graph');

    // Get TTL with proper prefixes
    $ttlData = $rdfGraph->serialise('turtle');
    error_log('TTL after serialization: ' . substr($ttlData, 0, 1000), 3, OMEKA_PATH . '/logs/namespace-debug.log');
    
    // Apply comprehensive cleanup
    $cleanTtl = $this->cleanupTtlOutput($ttlData);
    error_log('TTL after cleanup: ' . substr($cleanTtl, 0, 1000), 3, OMEKA_PATH . '/logs/namespace-debug.log');
    
    error_log('RDF-XML data converted to clean TTL');

    return $cleanTtl;
}

private function cleanRdfXmlNamespaces($rdfXmlData)
{
    // Define the namespace mappings we want
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
    
    // Create a DOMDocument to properly handle namespaces
    $dom = new \DOMDocument();
    $dom->loadXML($rdfXmlData);
    
    // Set proper namespace declarations on root element
    $root = $dom->documentElement;
    if ($root) {
        foreach ($namespaces as $prefix => $namespace) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$prefix", $namespace);
        }
    }
    
    return $dom->saveXML();
}

private function cleanupTtlOutput($ttlData)
{
    // Remove auto-generated namespace prefixes and replace with our clean ones
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



private function replaceNamespacePrefixes($content)
{

    // if is arrowhead, replace ah: with ah-shape:
    if (strpos($content, 'ah:Arrowhead') !== false || strpos($content, 'excav:Item') !== false) {
        $replacements = [
            '/ns0:/' => 'edm:',
            '/ns1:/' => 'dbo:',
            '/ns2:/' => 'crm:',
            // Add more as needed based on your namespace usage
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
    
    // CRITICAL FIX: Fix Dublin Core namespace issues
    $content = preg_replace('/dc:identifier/', 'dct:identifier', $content);
    $content = preg_replace('/dc:date/', 'dct:date', $content);
    $content = preg_replace('/dc:description/', 'dct:description', $content);
    
    return $content;
}

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
    
    // Fix closing angle brackets for URIs
    $content = str_replace('>', '>', $content);
    
    return $content;
}

private function fixKosUris($content)
{
    // Ensure KOS URIs use the correct format
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
    
    error_log('Using graph: ' . $graphUri, 3, OMEKA_PATH . '/logs/excavation-debug.log');

    try {
        $validationResult = $this->validateData($data, $graphUri);
        // log the validation result
        error_log('Validation Result: ' . implode('; ', $validationResult), 3, OMEKA_PATH . '/logs/validation-log.log');

        if (!empty($validationResult)) {
            $errorMessage = 'Data upload failed: SHACL validation errors: ' . implode('; ', $validationResult);
            error_log($errorMessage, 3, OMEKA_PATH . '/logs/graphdb-errors.log');
            $logger->err($errorMessage);
            return $errorMessage;
        }

        $credentials = $this->getGraphDBCredentials();


        // 2. Upload ONLY if validation passes
        $client = new Client();
        $fullUrl = $this->graphdbEndpoint . '?graph=' . urlencode($graphUri);
        error_log('Uploading to graph: ' . $fullUrl, 3, OMEKA_PATH . '/logs/graphdb-upload.log');
        
        $client->setUri($fullUrl);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'text/turtle',
            'Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['password'])
        ]);
        $client->setRawBody($data);

        $client->setOptions(['timeout' => 60]); // Adjust the timeout as needed

        $response = $client->send();

        $status = $response->getStatusCode();
        $body = $response->getBody();
        $message = "Response Status: $status | Response Body: $body";
        error_log($message, 3, OMEKA_PATH . '/logs/graphdb-response.log');
        $logger->info($message);


        if ($status == 401) {
            $errorMessage = "Authentication failed with GraphDB. Please check your credentials.";
            error_log($errorMessage, 3, OMEKA_PATH . '/logs/graphdb-errors.log');
            $logger->err($errorMessage);
            return $errorMessage;
        }


        if ($response->isSuccess()) {
            return 'Data uploaded and validated successfully.';
        } else {
            $errorMessage = 'Failed to upload data: ' . $message;
            error_log($errorMessage, 3, OMEKA_PATH . '/logs/graphdb-errors.log');
            $logger->err($errorMessage);
            return $errorMessage;
        }
    } catch (\Exception $e) {
        $errorMessage = 'Failed to upload data due to an exception: ' . $e->getMessage();
        $logger->err($errorMessage);
        error_log($errorMessage, 3, OMEKA_PATH . '/logs/graphdb-errors.log');
        return $errorMessage;
    }
}




private function getGraphDBCredentials()
{
    // First try to load credentials from config file
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

    // Last resort fallback for backward compatibility
    return [
        'username' => 'admin',
        'password' => 'admin'
    ];
}


private function validateData($data, $graphUri)
{
    $errors = [];
    $logger = new Logger(); // Initialize logger here
    $writer = new Stream(OMEKA_PATH . '/logs/graphdb-errors.log');
    $logger->addWriter($writer);

    try {

        $credentials = $this->getGraphDBCredentials();

        // 1. Prepare the validation query
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

        // 2. Execute the validation query
        $client = new Client();
        $client->setUri($this->graphdbQueryEndpoint);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'application/sparql-results+json', // Crucial: Request JSON results
            'Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['password'])
        ]);
        $client->setRawBody($query);
        $response = $client->send();
        if ($response->getStatusCode() == 401) {
            $errorMessage = "Authentication failed with GraphDB. Please check your credentials.";
            $logger->err($errorMessage);
            error_log($errorMessage);
            return [$errorMessage];
        }

        if (!$response->isSuccess()) {
            $errorMessage = "SHACL validation query failed: " . $response->getStatusCode() . " - " . $response->getBody();
            $logger->err($errorMessage);
            error_log($errorMessage);
            return [$errorMessage];
        }

        $rawBody = $response->getBody();
        error_log("Raw GraphDB Response: " . $rawBody); // Keep logging the raw response

        $results = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = "Error decoding JSON response: " . json_last_error_msg() . " Raw Body: " . $rawBody; // Include raw body in error
            $logger->err($errorMessage);
            error_log($errorMessage);
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
        error_log($errorMessage);
        return [$errorMessage];
    }

    return $errors;
}



private function getExcavationIdentifierFromItemSet($itemSetId)
{
    // Get mappings from site settings
    $mappings = $this->siteSettings()->get('excavation_itemset_mappings', []);
    
    if (isset($mappings[$itemSetId])) {
        return $mappings[$itemSetId];
    }
    
    // If no mapping found, try to extract from item set title
    try {
        $itemSet = $this->api()->read('item_sets', $itemSetId)->getContent();
        $title = $itemSet->displayTitle();
        
        // Parse the title to extract excavation ID if it follows our pattern
        if (preg_match('/Excavation\s+([^\s]+)/', $title, $matches)) {
            $excavationId = $matches[1];
            
            // Store this mapping for future use
            $this->storeMappingBetweenItemSetAndExcavation($itemSetId, $excavationId);
            
            return $excavationId;
        }
    } catch (\Exception $e) {
        error_log('Error retrieving item set: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/excavation-debug.log');
    }
    
    return null;
}





private function transformTtlToOmekaSData($ttlData, $itemSetId = null): array {
    error_log('Transforming TTL to Omeka S data', 3, OMEKA_PATH . '/logs/transform.log');
    $graph = new \EasyRdf\Graph();
    $graph->parse($ttlData, 'turtle');
    
    $omekaData = [];
    $rdfData = $graph->toRdfPhp();

    error_log('RDF Data subjects found: ' . count($rdfData), 3, OMEKA_PATH . '/logs/transform.log');
    
    // Find main subjects (items that should become Omeka items)
    $mainSubjects = $this->identifyMainSubjects($rdfData, $itemSetId);
    
    // Get excavation identifier for context
    $excavationId = "0"; // Default
    if ($itemSetId) {
        $mappedId = $this->getExcavationIdentifierFromItemSet($itemSetId);
        if ($mappedId) {
            $excavationId = $mappedId;
        }
    }
    
    // Process each main subject as a separate Omeka item
    foreach ($mainSubjects as $subject => $subjectType) {
        $itemData = [
            'o:resource_class' => ['o:id' => 1], // Default Item Resource Class ID
            'o:item_set' => [],
        ];
        
        // Add item to item set if provided
        if ($itemSetId) {
            $itemData['o:item_set'][] = ['o:id' => $itemSetId];
        }
        
        // Extract identifier and create title
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
        
        // Set title
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
        
        // Extract common properties first
        $this->extractCommonProperties($rdfData, $subject, $itemData);
        error_log('subject type: ' . $subjectType, 3, OMEKA_PATH . '/logs/subject.log');
        // Process based on subject type
        switch ($subjectType) {
            case 'arrowhead':
            case 'item':
                $this->processArrowheadData($rdfData, $subject, $itemData);
                break;
            case 'excavation':
                error_log('Processing excavation data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/abcd.log');
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

    error_log('Transformed ' . count($omekaData) . ' items for Omeka S', 3, OMEKA_PATH . '/logs/transform.log');
    
    return $omekaData;
}


private function isMainArrowheadItem($rdfData, $subject) {
    if (!isset($rdfData[$subject])) {
        return false;
    }
    
    $predicates = $rdfData[$subject];
    
    // Main arrowhead items should have arrowhead-specific properties
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

private function isNewEncounterEvent($rdfData, $subject) {
    if (!isset($rdfData[$subject])) {
        return false;
    }
    
    $predicates = $rdfData[$subject];
    
    // Check if this encounter event has the "encountered_object" property
    // which indicates it's a real encounter event, not just a declaration
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

private function identifyMainSubjects($rdfData, $itemSetId = null) {
    $subjects = [];
    
    error_log('=== IDENTIFYING MAIN SUBJECTS ===', 3, OMEKA_PATH . '/logs/main-subjects.log');
    error_log('Total RDF subjects: ' . count($rdfData), 3, OMEKA_PATH . '/logs/main-subjects.log');
    
    // UPDATED patterns to match both original and normalized URIs
    $mainSubjectTypes = [
        // Original namespace patterns
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
    
    // DYNAMIC: Add normalized patterns based on itemSetId if available
    if ($itemSetId) {
        $normalizedPatterns = [
            "http://localhost/megalod/$itemSetId/ah/Arrowhead" => 'arrowhead',
            "http://localhost/megalod/$itemSetId/excavation/Item" => 'item', 
            "http://localhost/megalod/$itemSetId/excavation/Excavation" => 'excavation',
            "http://localhost/megalod/$itemSetId/excavation/Context" => 'context',
            "http://localhost/megalod/$itemSetId/excavation/StratigraphicVolumeUnit" => 'svu',
            "http://localhost/megalod/$itemSetId/excavation/Square" => 'square',
        ];
        
        // Merge normalized patterns
        $mainSubjectTypes = array_merge($mainSubjectTypes, $normalizedPatterns);
        
        error_log("Added normalized patterns for itemSetId: " . $itemSetId, 3, OMEKA_PATH . '/logs/main-subjects.log');
    }
    
    // Define the excluded subject types to prevent creating empty objects
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
    
    // Add dynamic excluded types based on itemSetId
    if ($itemSetId) {
        $excludedTypes = array_merge($excludedTypes, [
            "http://localhost/megalod/$itemSetId/excavation/Location",
            "http://localhost/megalod/$itemSetId/excavation/GPSCoordinates", 
            "http://localhost/megalod/$itemSetId/excavation/Archaeologist",
            "http://localhost/megalod/$itemSetId/excavation/TimeLine",
            "http://localhost/megalod/$itemSetId/excavation/Instant",
        ]);
    }
    
    // CRITICAL FIX: Determine if this is a complete excavation upload vs adding items to existing excavation
    $hasExcavationInData = false;
    $hasArrowheadsInData = false;
    
    // First pass: check what types of data we have
    foreach ($rdfData as $subject => $predicates) {
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                if ($typeObj['type'] === 'uri') {
                    // Check for excavation
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
        
    error_log("Data analysis: hasExcavation=$hasExcavationInData, hasArrowheads=$hasArrowheadsInData", 3, OMEKA_PATH . '/logs/main-subjects.log');
    error_log("Upload type: isCompleteExcavation=$isCompleteExcavationUpload, isArrowheadOnly=$isArrowheadOnlyUpload", 3, OMEKA_PATH . '/logs/main-subjects.log');

    // CRITICAL FIX: If uploading to an existing item set, ONLY process arrowhead items (not context/svu/square)
    if ($itemSetId && !$isCompleteExcavationUpload) {
        error_log("ItemSet ID provided: $itemSetId - ONLY including arrowhead/item and encounter subjects", 3, OMEKA_PATH . '/logs/main-subjects.log');

        // Find arrowhead/item subjects
        $arrowheadSubjects = [];
        foreach ($rdfData as $subject => $predicates) {
            if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri') {
                        // Only include arrowhead/item types
                        if ($typeObj['value'] === 'https://purl.org/megalod/ms/ah/Arrowhead' ||
                            $typeObj['value'] === 'ah:Arrowhead' ||
                            ($itemSetId && $typeObj['value'] === "http://localhost/megalod/$itemSetId/ah/Arrowhead")) {
                            
                            $arrowheadSubjects[$subject] = 'arrowhead';
                            error_log("✓ Found arrowhead subject: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                        }
                        else if (($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/Item' ||
                                $typeObj['value'] === 'excav:Item' ||
                                ($itemSetId && $typeObj['value'] === "http://localhost/megalod/$itemSetId/excavation/Item")) &&
                                $this->isMainArrowheadItem($rdfData, $subject)) {
                            
                            $arrowheadSubjects[$subject] = 'item';
                            error_log("✓ Found main item subject: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                        }
                    }
                }
            }
        }
        
        // Also include encounter events
        foreach ($rdfData as $subject => $predicates) {
            if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
                foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                    if ($typeObj['type'] === 'uri' && 
                        ($typeObj['value'] === 'https://purl.org/megalod/ms/excavation/EncounterEvent' ||
                         $typeObj['value'] === 'excav:EncounterEvent')) {
                        
                        // Only include NEW encounter events, not existing ones
                        if ($this->isNewEncounterEvent($rdfData, $subject)) {
                            $arrowheadSubjects[$subject] = 'encounter';
                            error_log("✓ Found new encounter event subject: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
                        }
                    }
                }
            }
        }
        
        // When uploading to an existing item set, ONLY return arrowhead/item/encounter subjects
        error_log("Returning ONLY arrowhead/item/encounter subjects when uploading to item set: " . count($arrowheadSubjects), 3, OMEKA_PATH . '/logs/main-subjects.log');
        return $arrowheadSubjects;
    }

    // For complete excavation uploads, proceed with normal processing
    // (This section handles the initial excavation creation, not individual arrowhead uploads)
    foreach ($rdfData as $subject => $predicates) {
        // Skip subjects that were already marked as excluded
        if (isset($subjects[$subject]) && $subjects[$subject] === 'excluded') {
            continue;
        }

        error_log("Checking subject: $subject", 3, OMEKA_PATH . '/logs/main-subjects.log');
        
        if (isset($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'])) {
            foreach ($predicates['http://www.w3.org/1999/02/22-rdf-syntax-ns#type'] as $typeObj) {
                error_log("  Found type: " . $typeObj['value'], 3, OMEKA_PATH . '/logs/main-subjects.log');
                
                // Skip encounter events and other auxiliary types
                if ($typeObj['type'] === 'uri' && 
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/EncounterEvent' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/ah/Morphology' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/ah/Chipping' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/TypometryValue' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/Weight' &&
                    $typeObj['value'] !== 'https://purl.org/megalod/ms/excavation/Coordinates' &&
                    !in_array($typeObj['value'], $excludedTypes) &&  // Check against the excluded types
                    isset($mainSubjectTypes[$typeObj['value']])) {
                    
                    $subjects[$subject] = $mainSubjectTypes[$typeObj['value']];
                    error_log("  ✓ Added as main subject: {$mainSubjectTypes[$typeObj['value']]}", 3, OMEKA_PATH . '/logs/main-subjects.log');
                    break; // Found the type, move to next subject
                }
                
                // If it's an excluded type, mark it to prevent processing in the fallback
                if (in_array($typeObj['value'], $excludedTypes)) {
                    $subjects[$subject] = 'excluded';
                    error_log("  ✓ Marked as excluded auxiliary type: {$typeObj['value']}", 3, OMEKA_PATH . '/logs/main-subjects.log');
                    break;
                }
            }
        }
    }
    
    // Remove excluded subjects from the result
    $subjects = array_filter($subjects, function($type) {
        return $type !== 'excluded';
    });
    
    error_log('Final subjects identified: ' . print_r($subjects, true), 3, OMEKA_PATH . '/logs/main-subjects.log');
    
    return $subjects;
}


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
        error_log("Error querying location data: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/location-data.log');
    }
    
    return null;
}


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




private function processArrowheadData($rdfData, $subject, &$itemData) {
    error_log('=== ENHANCED ARROWHEAD PROCESSING ===', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    error_log('Processing arrowhead data for subject: ' . $subject, 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
    
    // Current item set context
    $currentItemSetId = $this->getCurrentItemSetContext();
    
    // 1. DIRECT ARROWHEAD PROPERTIES - Extract all direct properties first
    $this->extractDirectArrowheadProperties($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 2. MEASUREMENTS - Extract all measurement values with units
    $this->extractAllMeasurements($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 3. MORPHOLOGY DATA - Extract complete morphology information
    $this->extractCompleteMorphologyData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 4. CHIPPING DATA - Extract complete chipping information  
    $this->extractCompleteChippingData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 5. COORDINATES - Extract coordinate information (ENHANCED)
    $this->extractCoordinateDataEnhanced($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 6. ENCOUNTER EVENT - Extract encounter event details (ENHANCED)
    $this->extractEncounterEventData($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 7. ARCHAEOLOGICAL CONTEXT - Extract excavation, location, square references (ENHANCED)
    $this->extractArchaeologicalContext($rdfData, $subject, $itemData, $currentItemSetId);
    
    // 8. IMAGES/MEDIA - Extract web resources
    $this->extractMediaResources($rdfData, $subject, $itemData);
    
    // 9. GPS COORDINATES - Extract GPS coordinates from the arrowhead 
    $this->extractGPSCoordinates($rdfData, $subject, $itemData, $currentItemSetId);
    error_log('Finished enhanced arrowhead processing', 3, OMEKA_PATH . '/logs/arrowhead-enhanced.log');
}


private function extractGPSCoordinates($rdfData, $subject, &$itemData, $currentItemSetId) {
    // Look for hasGPSCoordinates property first
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
                        
                        error_log("Added GPS Coordinates: $coordStr", 3, OMEKA_PATH . '/logs/gps-extraction.log');
                        return;
                    }
                }
            }
        }
    }
}


/**
 * Extracts direct arrowhead properties from RDF data for a specific subject.
 * 
 * This method processes RDF data to extract specific arrowhead-related properties
 * including shape, variant, material, elongation index, and thickness index.
 * It handles multiple URI variations for each property to ensure compatibility
 * with different RDF serialization formats.
 * 
 * The extracted properties are added to the itemData array with appropriate
 * Omeka S property IDs and labeled for display.
 * 
 * @param array $rdfData The parsed RDF data containing triples
 * @param string $subject The RDF subject URI to extract properties from
 * @param array &$itemData Reference to the item data array to populate with extracted properties
 * @param int $currentItemSetId The current item set ID used for generating local URIs
 * 
 * @return void The method updates the $itemData array by reference
 */



/**
 * Maps a subject type code to its corresponding display name.
 *
 * This method takes a simplified type identifier and returns a human-readable
 * item type name for display purposes. If the provided subject type is not found
 * in the mapping, it defaults to 'Archaeological Object'.
 *
 * @param string $subjectType The simplified type identifier (e.g., 'arrowhead', 'context', 'svu')
 * @return string The human-readable item type name
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
 * Sends item data to Omeka S API to create new items.
 * 
 * This method handles the creation of items in Omeka S through its API. It performs the following operations:
 * - Checks for duplicate identifiers within the batch
 * - Validates if items already exist in the specified item set
 * - Creates new items via the Omeka S API
 * - Attaches media files to the created items
 * - Updates the item set with excavation information if available
 * 
 * @param array $omekaData An array of item data formatted according to Omeka S API requirements
 * @param int|null $itemSetId Optional item set ID to associate the items with
 * 
 * @return array An associative array containing:
 *               - 'errors': Array of error messages that occurred during processing
 *               - 'created_items': Array of successfully created item data
 *               - 'skipped_items': Array of items that were skipped (with reasons)
 * 
 * @throws \Exception Implicitly may throw exceptions from HTTP client operations
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
    
    // First, check for duplicate identifiers within the current data
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
        
        // Skip items with duplicate identifiers in the current batch
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
            error_log('Omeka S API Error: ' . $response->getBody());
        } else {
            $createdItem = json_decode($response->getBody(), true);
            if ($createdItem && isset($createdItem['o:id'])) { // Assuming $createdItem might be an object with array-like access or an array
                $itemId = $createdItem['o:id'];
                // Attach media to the newly created item
                $this->attachMediaToItem($createdItem['o:id']);
                error_log('Omeka S Item Created Successfully: ID=' . $itemId . 
                 ($identifier ? ", Identifier=$identifier" : ""));
                                
            } else {
                
                $itemId = null; // Or some other appropriate default
                error_log('Warning: $createdItem is null or o:id not found at ' . __FILE__ . ' on line ' . __LINE__);
            }
            
            // Handle media files if they exist
            $this->attachMediaToItem($itemId);
            
            $createdItems[] = $createdItem;
            error_log('Omeka S Item Created Successfully: ID=' . $itemId . ($identifier ? ", Identifier=$identifier" : ""));
        }
    }

    if ($itemSetId && !empty($createdItems) && $this->excavationData) {
        // Update the item set with excavation info
        $this->updateItemSetWithExcavationInfo($itemSetId, $this->excavationData);
    }

    // Log skipped items for debugging
    if (!empty($skippedItems)) {
        error_log('Skipped items due to duplicate identifiers: ' . print_r($skippedItems, true), 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
    }

    return [
        'errors' => $errors,
        'created_items' => $createdItems,
        'skipped_items' => $skippedItems
    ];
}

/**
 * Checks if an item with the specified Dublin Core identifier already exists within a given item set
 * 
 * This method searches for items that have a matching dcterms:identifier property value
 * within the specified item set. It uses the Omeka S API to perform the search and
 * logs the process for debugging purposes.
 * 
 * @param string $identifier The Dublin Core identifier value to search for
 * @param int $itemSetId The ID of the item set to search within
 * @return bool Returns true if an item with the identifier exists, false otherwise or on error
 * 
 * @throws \Exception Catches and logs any exceptions that occur during the search process
 */
private function itemExistsWithIdentifier($identifier, $itemSetId) {
    try {
        error_log("Checking for existing item with identifier '$identifier' in item set #$itemSetId", 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
        
        
        // Search for items with the given identifier in the specified item set
        $searchParams = [
            'property' => [
                [
                    'property' => 10, // dcterms:identifier property ID
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
            error_log("Found existing item with ID {$existingItem->id()} having identifier '$identifier'", 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
            return true;
        }
        
        error_log("No existing item found with identifier '$identifier' in item set #$itemSetId", 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
        return false;
    } catch (\Exception $e) {
        error_log("Error checking for existing identifier: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/duplicate-identifiers.log');
        return false; // Assume no duplicate in case of error
    }
}


/**
 * Extracts the identifier value from item data.
 * 
 * This method searches for a Dublin Core identifier (dcterms:identifier) in the provided item data
 * and returns the first found @value. If no identifier is found, it returns null.
 *
 * @param array $itemData The item data array containing property values
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
 * Attaches uploaded media files to an Omeka-S item.
 * 
 * This method processes previously stored file uploads from $this->uploadedFiles
 * and creates media resources attached to the specified item. The method:
 * 1. Checks for valid uploaded files
 * 2. Processes each file individually
 * 3. Creates media resources via Omeka API
 * 4. Logs the process for debugging purposes
 *
 * The method uses a temporary storage approach to handle files between
 * the upload and Omeka's ingestion process.
 *
 * @param int $itemId The ID of the Omeka-S item to attach media to
 * @return void
 * 
 * @throws \Exception Various exceptions may be caught internally during media creation
 */
private function attachMediaToItem($itemId) {
    error_log('Attempting to attach media to item ID: ' . $itemId, 3, OMEKA_PATH . '/logs/media-debug.log');
    
    // Use stored files instead of $_FILES
    if ($this->uploadedFiles && isset($this->uploadedFiles['name']) && is_array($this->uploadedFiles['name'])) {
        error_log('Found ' . count($this->uploadedFiles['name']) . ' uploaded files', 3, OMEKA_PATH . '/logs/media-debug.log');
        
        for ($i = 0; $i < count($this->uploadedFiles['name']); $i++) {
            if ($this->uploadedFiles['error'][$i] === UPLOAD_ERR_OK) {
                $tempFile = $this->uploadedFiles['tmp_name'][$i];
                $filename = $this->uploadedFiles['name'][$i];
                $mimeType = $this->uploadedFiles['type'][$i];
                
                error_log("Processing file: $filename ($mimeType)", 3, OMEKA_PATH . '/logs/media-debug.log');
                
                // Create media for this file
                try {
                    // Create the media via Omeka API
                    $mediaData = [
                        'o:ingester' => 'upload',
                        'o:item' => ['o:id' => $itemId],
                        'dcterms:title' => [
                            [
                                'type' => 'literal',
                                'property_id' => 1, // dcterms:title property ID
                                '@value' => $filename
                            ]
                        ]
                    ];
                    
                    // Prepare the file for upload - copy to temp location
                    $tempDir = sys_get_temp_dir();
                    $targetPath = $tempDir . '/' . uniqid('omeka_upload_') . '_' . basename($filename);
                    if (copy($tempFile, $targetPath)) {
                        error_log("Copied file to temporary location: $targetPath", 3, OMEKA_PATH . '/logs/media-debug.log');
                        
                        // Set up the file upload structure that Omeka expects
                        $_FILES = [
                            'file' => [
                                'name' => [$filename],
                                'type' => [$mimeType],
                                'tmp_name' => [$targetPath],
                                'error' => [0],
                                'size' => [filesize($tempFile)]
                            ]
                        ];
                        
                        // Create the media
                        $response = $this->api()->create('media', $mediaData);
                        error_log("Successfully created media for item $itemId: " . $response->getContent()->id(), 3, OMEKA_PATH . '/logs/media-debug.log');
                    } else {
                        error_log("Failed to copy file to temporary location", 3, OMEKA_PATH . '/logs/media-debug.log');
                    }
                } catch (\Exception $e) {
                    error_log("Failed to create media for item $itemId: " . $e->getMessage(), 3, OMEKA_PATH . '/logs/media-debug.log');
                }
            } else {
                error_log("Upload error for file index $i: " . $this->uploadedFiles['error'][$i], 3, OMEKA_PATH . '/logs/media-debug.log');
            }
        }
    } else {
        error_log('No valid uploaded files found to attach', 3, OMEKA_PATH . '/logs/media-debug.log');
    }
}


/**
 * Search action handler for the site frontend search functionality
 * 
 * Processes search queries and various filters to search across items and item sets.
 * Handles basic text search as well as specialized filters for archaeological artifacts,
 * particularly arrowheads.
 * 
 * The search can filter by:
 * - General search query
 * - Search type (items, item_sets, or all)
 * - Excavation-specific filters (archaeologist, ORCID, country, district, parish)
 * - Arrowhead basic filters (shape, variant, material, elongation)
 * - Arrowhead morphology (thickness, base, condition)
 * - Arrowhead chipping characteristics (mode, direction, delineation, shape, amplitude)
 * - Measurement ranges (height, width, thickness, weight)
 * 
 * The method constructs appropriate queries for the Omeka S API based on the provided filters,
 * and returns a ViewModel with search results and all filter parameters for the view.
 * 
 * @return \Laminas\View\Model\ViewModel The view model containing search results and parameters
 */
public function searchAction()
{
    $request = $this->getRequest();
    $searchQuery = $request->getQuery('query', '');
    $searchType = $request->getQuery('type', 'all'); // 'items', 'item_sets', or 'all'
    $page = $request->getQuery('page', 1);
    $perPage = 20;
    
    // Excavation-specific filters
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
    
    // Advanced filters - morphology
    $filterThickness = $request->getQuery('thickness', '');
    $filterBase = $request->getQuery('base', '');
    $filterCondition = $request->getQuery('condition', '');
    
    // Advanced filters - chipping
    $filterChippingMode = $request->getQuery('chippingMode', '');
    $filterChippingDirection = $request->getQuery('chippingDirection', '');
    $filterChippingDelineation = $request->getQuery('chippingDelineation', '');
    $filterChippingShape = $request->getQuery('chippingShape', '');
    $filterChippingAmplitude = $request->getQuery('chippingAmplitude', '');
    
    // Measurement range filters
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
    
    // Get filter options from GraphDB
    $archaeologistOptions = $this->getArchaeologistOptions();
    $countryOptions = $this->getCountryOptions();
    $districtOptions = $this->getDistrictOptions();
    $parishOptions = $this->getParishOptions();
    
    $hasFilters = $filterShape || $filterVariant || $filterMaterial || $filterElongation || 
              $filterThickness || $filterBase || $filterCondition || $filterChippingMode || 
              $filterChippingDirection || $filterChippingDelineation || $filterChippingShape || 
              $filterChippingAmplitude || $minHeight || $maxHeight || $minWidth || $maxWidth || 
              $minThickness || $maxThickness || $minWeight || $maxWeight ||
              $filterArchaeologist || $filterOrcid || $filterCountry || $filterDistrict || $filterParish;

    if ($searchQuery || $hasFilters) {
        if ($searchType === 'all' || $searchType === 'item_sets') {
    $itemSetQuery = [];
    
    // Basic search query
    if ($searchQuery) {
        $itemSetQuery['fulltext_search'] = $searchQuery;
    }
    
    // If we have excavation filters, we need to search excavation items first
    if ($filterArchaeologist || $filterOrcid || $filterCountry || $filterDistrict || $filterParish) {
        // Search for excavation items with these properties
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
        
        // Also filter by title pattern to only get excavation items
        $excavationItemQuery['fulltext_search'] = 'Excavation';
        
        error_log("Searching excavation items with query: " . print_r($excavationItemQuery, true), 3, OMEKA_PATH . '/logs/search-debug.log');
        
        // Search for excavation items
        $excavationItemsResponse = $this->api()->search('items', $excavationItemQuery);
        $excavationItems = $excavationItemsResponse->getContent();
        
        error_log("Found " . count($excavationItems) . " excavation items", 3, OMEKA_PATH . '/logs/search-debug.log');
        
        // Extract item set IDs from the matching excavation items
        $itemSetIds = [];
        foreach ($excavationItems as $item) {
            $itemSets = $item->itemSets();
            foreach ($itemSets as $itemSet) {
                $itemSetIds[] = $itemSet->id();
                error_log("Found item set ID: " . $itemSet->id() . " from excavation item: " . $item->displayTitle(), 3, OMEKA_PATH . '/logs/search-debug.log');
            }
        }
        
        // Remove duplicates
        $itemSetIds = array_unique($itemSetIds);
        
        if (!empty($itemSetIds)) {
            // Search item sets by their IDs
            $itemSetQuery['id'] = $itemSetIds;
            
            // Also add the basic search query if provided
            if ($searchQuery) {
                // We need to combine the ID filter with the fulltext search
                // This might require a more complex approach
                unset($itemSetQuery['fulltext_search']); // Remove fulltext for now when filtering
            }
            
            error_log("Final item set query: " . print_r($itemSetQuery, true), 3, OMEKA_PATH . '/logs/search-debug.log');
            
            $itemSetsResponse = $this->api()->search('item_sets', $itemSetQuery);
            $results['item_sets'] = $itemSetsResponse->getContent();
            $totalItemSets = $itemSetsResponse->getTotalResults();
        } else {
            // No matching excavation items found
            $results['item_sets'] = [];
            $totalItemSets = 0;
        }
        
        error_log("Final result: Found $totalItemSets item sets", 3, OMEKA_PATH . '/logs/search-debug.log');
    } else {
        // No excavation filters, search item sets normally
        $itemSetsResponse = $this->api()->search('item_sets', $itemSetQuery);
        $results['item_sets'] = $itemSetsResponse->getContent();
        $totalItemSets = $itemSetsResponse->getTotalResults();
    }
}
        
        // Search for items if search type is 'all' or 'items'
        if ($searchType === 'all' || $searchType === 'items') {
            $itemQuery = [];
            
            // Basic search query
            if ($searchQuery) {
                $itemQuery['fulltext_search'] = $searchQuery;
            }
            
            // Apply arrowhead property filters
            $propertyFilters = [];
            
            // Basic filters
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
            
            // Advanced filters - morphology
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
            
            // Advanced filters - chipping
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
            
            // Measurement range filters - we'll use regex to extract the numeric parts
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
            
            // Add property filters if any
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

/**
 * Retrieves a list of archaeologists from the triplestore with their names and ORCID identifiers.
 * 
 * Executes a SPARQL query to GraphDB that:
 * - Finds all excavation entries
 * - Gets the persons in charge of those excavations
 * - Extracts their names
 * - Optionally extracts their ORCID IDs when available
 * - Orders the results alphabetically by name
 * 
 * The SPARQL query uses the following prefixes:
 * - foaf: <http://xmlns.com/foaf/0.1/>
 * - excav: <https://purl.org/megalod/ms/excavation/>
 *
 * @return array An array of archaeologists, each containing:
 *               - name: The archaeologist's name
 *               - orcid: The archaeologist's ORCID identifier (null if not available)
 * 
 * @throws \Exception If there's an error during the GraphDB query execution
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
        
        // Debug log
        error_log('Found ' . count($archaeologists) . ' archaeologists from GraphDB', 3, OMEKA_PATH . '/logs/filter-options.log');
        
        return $archaeologists;
    } catch (\Exception $e) {
        error_log('Error querying archaeologists: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/filter-options.log');
        return [];
    }
}



/**
 * Retrieves a list of country names from GraphDB.
 * 
 * This method sends a SPARQL query to GraphDB to fetch all distinct country names
 * from DBpedia. The query looks for resources of type dbo:Country and extracts
 * their names from the resource URIs.
 * 
 * @return array An array of country names. If the query fails or returns no results,
 *               a default set of country names is returned.
 * @throws \Exception Exceptions from the GraphDB query are caught and logged.
 *                    The method will return default countries in case of error.
 */
private function getCountryOptions()
{
    $query = "
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX dbo: <http://dbpedia.org/ontology/>

SELECT DISTINCT ?countryName
WHERE {
  ?country rdf:type dbo:Country .
  BIND(REPLACE(STR(?country), 'http://dbpedia.org/resource/', '') AS ?countryName)}";
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
        
        // If no countries found, provide some defaults
        if (empty($countries)) {
            $countries = [];
        }
        
        // Debug log
        error_log('Found ' . count($countries) . ' countries from GraphDB', 3, OMEKA_PATH . '/logs/filter-options.log');
        
        return $countries;
    } catch (\Exception $e) {
        error_log('Error querying countries: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/filter-options.log');
        return ['Portugal', 'Spain', 'France', 'Italy'];
    }
}


/**
 * Retrieves a list of district names from GraphDB.
 *
 * This method executes a SPARQL query to retrieve distinct district names from DBpedia.
 * The query finds all resources that are of type dbo:District and extracts their names
 * by removing the "http://dbpedia.org/resource/" prefix from their URIs.
 *
 * @return array An array of district names as strings, or an empty array if an error occurs
 *               or if no districts are found
 * 
 * @throws \Exception Exceptions from the GraphDB query are caught internally and logged
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
        
        // Debug log
        error_log('Found ' . count($districts) . ' districts from GraphDB', 3, OMEKA_PATH . '/logs/filter-options.log');
        
        return $districts;
    } catch (\Exception $e) {
        error_log('Error querying districts: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/filter-options.log');
        return [];
    }
}

/**
 * Retrieves a list of parish names from the GraphDB triplestore.
 * 
 * This method executes a SPARQL query to the GraphDB endpoint to fetch all distinct
 * parish names from resources of type "Parish". The query retrieves DBpedia parish resources
 * and extracts just the name portion from the full URI.
 * 
 * The method handles any connection errors or empty results gracefully, logging debug
 * information to the filter-options.log file.
 * 
 * @return array List of parish names extracted from DBpedia resources
 * @throws \Exception May throw exceptions during GraphDB query execution which are caught internally
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
        
        // Debug log
        error_log('Found ' . count($parishes) . ' parishes from GraphDB', 3, OMEKA_PATH . '/logs/filter-options.log');
        
        return $parishes;
    } catch (\Exception $e) {
        error_log('Error querying parishes: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/filter-options.log');
        return [];
    }
}



/**
 * Executes a SPARQL query against a GraphDB endpoint.
 *
 * This method handles the HTTP communication with the configured GraphDB endpoint,
 * sending the provided SPARQL query and processing the response.
 *
 * @param string $queryString The SPARQL query string to execute
 * @return array|null The query results as an associative array, or null if the query fails
 * 
 * @throws \Exception May throw exceptions during HTTP communication, which are caught internally
 *
 * @see getGraphDBCredentials() Used to retrieve GraphDB authentication credentials
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
            error_log('GraphDB query failed: ' . $response->getStatusCode() . ' - ' . $response->getBody(), 3, OMEKA_PATH . '/logs/graphdb-errors.log');
            return null;
        }
    } catch (\Exception $e) {
        error_log('Error executing GraphDB query: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/graphdb-errors.log');
        return null;
    }
}


/**
 * Action for viewing detailed information about an Omeka S resource.
 *
 * This method handles requests to view detailed information about items and item sets
 * in the Omeka S system. It retrieves the resource based on the provided ID and type,
 * processes its metadata properties, and for item sets, also fetches related items.
 *
 * The method employs multiple approaches to find items belonging to an item set:
 * 1. First attempts a direct API search using item_set_id
 * 2. If no results are found, falls back to retrieving all items and manually filtering
 *
 * For all resources, it processes property values to create a structured array of metadata,
 * with human-readable labels, sorted alphabetically by label for display purposes.
 *
 * @return \Laminas\View\Model\ViewModel|\ Laminas\Http\Response The view model with resource data or a redirect response
 */
public function viewDetailsAction()
{
    $request = $this->getRequest();
    $resourceType = $request->getQuery('type', 'item');
    $id = $request->getQuery('id');
    
    if (!$id) {
        return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
    }
    
    $resource = null;
    $properties = [];
    $relatedItems = []; // Initialize here
    
    try {
        if ($resourceType === 'item_set') {
            $resource = $this->api()->read('item_sets', $id)->getContent();
            
            error_log("=== DEBUGGING ITEM SET $id ===", 3, OMEKA_PATH . '/logs/related-items-debug.log');
            error_log("Item set title: " . $resource->displayTitle(), 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
            // Try different search approaches
            
            // Approach 1: Direct item_set_id search
            $searchParams1 = [
                'item_set_id' => $id,
                'sort_by' => 'created',
                'sort_order' => 'desc',
                'per_page' => 50
            ];
            
            error_log("Search params 1: " . print_r($searchParams1, true), 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
            $response1 = $this->api()->search('items', $searchParams1);
            $relatedItems = $response1->getContent();
            $totalResults1 = $response1->getTotalResults();
            
            error_log("Approach 1 - Found $totalResults1 items using item_set_id", 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
            // Approach 2: If no results, try searching all items and filter
            if (empty($relatedItems)) {
                error_log("Trying approach 2 - searching all items", 3, OMEKA_PATH . '/logs/related-items-debug.log');
                
                $allItemsResponse = $this->api()->search('items', ['per_page' => 100]);
                $allItems = $allItemsResponse->getContent();
                
                error_log("Total items in system: " . count($allItems), 3, OMEKA_PATH . '/logs/related-items-debug.log');
                
                foreach ($allItems as $item) {
                    $itemSets = $item->itemSets();
                    foreach ($itemSets as $itemSet) {
                        if ($itemSet->id() == $id) {
                            $relatedItems[] = $item;
                            error_log("Found item: " . $item->displayTitle() . " (ID: " . $item->id() . ")", 3, OMEKA_PATH . '/logs/related-items-debug.log');
                        }
                    }
                }
            }
            
            error_log("Final count of related items: " . count($relatedItems), 3, OMEKA_PATH . '/logs/related-items-debug.log');
            
        } else {
            $resource = $this->api()->read('items', $id)->getContent();
        }
        
        // Get all values using the proper Omeka S method
        $values = $resource->values();
        
        foreach ($values as $term => $propertyData) {
            try {
                // Skip empty data
                if (empty($propertyData)) {
                    continue;
                }
                
                $propertyLabel = $this->getHumanReadableLabel($term);
                $propertyValues = [];
                
                // Handle different possible structures
                if (is_array($propertyData)) {
                    // Look for 'values' key specifically
                    if (isset($propertyData['values']) && is_array($propertyData['values'])) {
                        $propertyValues = $propertyData['values'];
                        
                        // Try to get better label from property object
                        if (isset($propertyData['property']) && is_object($propertyData['property'])) {
                            if (method_exists($propertyData['property'], 'label')) {
                                $propertyLabel = $propertyData['property']->label();
                            }
                        }
                    } else {
                        // Try each item in the array
                        foreach ($propertyData as $item) {
                            if (is_object($item) && method_exists($item, 'value')) {
                                $propertyValues[] = $item;
                                
                                // Try to get label from first value's property
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
                    // Single value object
                    $propertyValues = [$propertyData];
                    
                    if (method_exists($propertyData, 'property')) {
                        $prop = $propertyData->property();
                        if ($prop && method_exists($prop, 'label')) {
                            $propertyLabel = $prop->label();
                        }
                    }
                }
                
                // Only add if we have valid values
                if (!empty($propertyValues)) {
                    $properties[] = [
                        'term' => $term,
                        'label' => $propertyLabel,
                        'values' => $propertyValues
                    ];
                }
                
            } catch (\Exception $e) {
                error_log("Error processing property '$term': " . $e->getMessage(), 3, OMEKA_PATH . '/logs/property-debug.log');
                continue;
            }
        }
        
        // Sort properties by label for better display
        usort($properties, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });
        
    } catch (\Exception $e) {
        error_log('Error fetching resource details: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/view-details-error.log');
        $this->messenger()->addError('The requested resource could not be found.');
        return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
    }
    
    error_log("Passing " . count($relatedItems) . " related items to view", 3, OMEKA_PATH . '/logs/related-items-debug.log');
    
    return new ViewModel([
        'resource' => $resource,
        'resourceType' => $resourceType,
        'properties' => $properties,
        'relatedItems' => $relatedItems,
        'site' => $this->currentSite()
    ]);
}



/**
 * Converts a property term to a human-readable label.
 * 
 * This method transforms ontology terms like 'dcterms:title' or 'crm:P44_has_condition'
 * into user-friendly labels like 'Title' or 'Condition'. It first checks against a predefined
 * mapping of common terms. If no match is found, it creates a readable label by:
 * 1. Removing the namespace prefix (e.g., 'dcterms:')
 * 2. Converting camelCase to space-separated words
 * 3. Capitalizing the first letter of each word
 * 
 * @param string $term The property term to convert (e.g., 'dcterms:title', 'schema:height')
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
    
    // Return mapped label if exists
    if (isset($labelMappings[$term])) {
        return $labelMappings[$term];
    }
    
    // Otherwise, create a readable label from the term
    // Remove namespace prefix
    $label = $term;
    if (strpos($label, ':') !== false) {
        $parts = explode(':', $label);
        $label = end($parts);
    }
    
    // Convert camelCase to Title Case
    $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);
    
    // Capitalize first letter of each word
    $label = ucwords($label);
    
    return $label;
}
    

/**
 * Downloads resource data in Turtle (TTL) format
 * 
 * This action handles the download of RDF data in Turtle format for either an item or an item set.
 * For item sets, it retrieves all data from the corresponding graph in the triplestore.
 * For individual items, it retrieves the specific item and its related data.
 * 
 * @return \Laminas\Http\Response|Laminas\View\Model\ViewModel
 *    Returns a file download response with TTL data or redirects to appropriate page on error
 * 
 * @throws \Exception When there's an error retrieving or processing the data
 * 
 * URL parameters:
 *   - id: The ID of the resource (item or item set) to download
 *   - type: The type of resource ('item' or 'item_set', defaults to 'item')
 */
public function downloadTtlAction()
{
    $id = $this->params()->fromQuery('id');
    $type = $this->params()->fromQuery('type', 'item');
    
    if (empty($id)) {
        return $this->redirect()->toRoute('site/add-triplestore/search', ['site-slug' => $this->currentSite()->slug()]);
    }
    
    try {
        // Get the resource to extract context information
        $resourceType = $type === 'item_set' ? 'item_sets' : 'items';
        $resource = $this->api()->read($resourceType, $id)->getContent();
        
        if ($type === 'item_set') {
            // For item sets, get all data from the corresponding graph
            $ttlData = $this->queryCompleteExcavationFromGraphDB($id, $resource);
        } else {
            // For individual items, get the specific item and its related data
            $ttlData = $this->queryItemFromGraphDB($resource, $id);
        }
        
        if (empty($ttlData)) {
            $this->messenger()->addError('No TTL data found for this resource.');
            return $this->redirect()->toRoute('site/add-triplestore/view-details', 
                ['site-slug' => $this->currentSite()->slug()],
                ['query' => ['id' => $id, 'type' => $type]]
            );
        }
        
        // Set response headers for download
        $filename = $this->sanitizeFilename($resource->displayTitle());
        
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/turtle; charset=UTF-8');
        $response->getHeaders()->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '.ttl"');
        $response->setContent($ttlData);
        
        return $response;
        
    } catch (\Exception $e) {
        error_log('Error in downloadTtlAction: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/download-error.log');
        $this->messenger()->addError('Error generating TTL data: ' . $e->getMessage());
        return $this->redirect()->toRoute('site/add-triplestore/view-details', 
            ['site-slug' => $this->currentSite()->slug()],
            ['query' => ['id' => $id, 'type' => $type]]
        );
    }
}


/**
 * Retrieves complete excavation data from GraphDB for a specific item set.
 * 
 * This method constructs a SPARQL CONSTRUCT query to retrieve all triples
 * from the graph associated with the provided item set ID. It uses the base
 * data graph URI with the item set ID appended to form the complete graph URI.
 * 
 * After retrieving the TTL data, it organizes and formats the data using
 * the organizeAndFormatTtl method. All actions are logged to the excavation
 * download log file.
 *
 * @param string $itemSetId The ID of the item set to query data for
 * @param mixed $resource The resource object (unused in this method but required by interface)
 * @return string|null The organized and formatted TTL data, or null if no data was retrieved
 */
private function queryCompleteExcavationFromGraphDB($itemSetId, $resource)
{
    $graphUri = $this->baseDataGraphUri . $itemSetId . "/";
    
    error_log("Querying complete excavation data for item set: $itemSetId in graph: $graphUri", 3, OMEKA_PATH . '/logs/excavation-download.log');
    
    // MUCH SIMPLER: Just get ALL triples in the graph
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
        // Organize and format the TTL data
        $organizedTtl = $this->organizeAndFormatTtl($ttlData, $itemSetId);
        
        error_log("Successfully retrieved and organized excavation TTL data. Length: " . strlen($organizedTtl), 3, OMEKA_PATH . '/logs/excavation-download.log');
        return $organizedTtl;
    }
    
    error_log("No TTL data retrieved for excavation item set: $itemSetId", 3, OMEKA_PATH . '/logs/excavation-download.log');
    return null;
}




/**
 * Parses Turtle (TTL) formatted RDF data into an array of subjects with their statements.
 *
 * This method processes TTL data line by line to extract subjects and their associated
 * predicates and objects. It handles both single-line and multi-line statements.
 *
 * The parsing process:
 * 1. Cleans and normalizes the TTL data by removing existing prefixes
 * 2. Processes the data line by line, identifying subjects and their statements
 * 3. Handles multi-line statements appropriately
 * 4. Cleans the gathered statements for each subject
 *
 * @param string $ttlData The Turtle formatted RDF data to parse
 * @return array An associative array where keys are subject URIs (enclosed in angle brackets)
 *               and values are arrays of predicate-object statements associated with those subjects
 */
private function parseTtlIntoSubjects($ttlData)
{
    $subjects = [];
    
    // First, clean and normalize the TTL data
    $cleanTtl = $this->cleanExistingPrefixes($ttlData);
    
    // Use a more robust parsing approach
    $lines = explode("\n", $cleanTtl);
    $currentSubject = null;
    $currentStatements = [];
    $inMultiLineStatement = false;
    
    foreach ($lines as $lineNum => $line) {
        $trimmedLine = trim($line);
        
        // Skip empty lines and comments
        if (empty($trimmedLine) || strpos($trimmedLine, '#') === 0) {
            continue;
        }
        
        // Check if this line starts a new subject
        if (preg_match('/^(<[^>]+>)\s+(.+)$/', $trimmedLine, $matches)) {
            // Save previous subject if exists
            if ($currentSubject && !empty($currentStatements)) {
                $subjects[$currentSubject] = $this->cleanStatements($currentStatements);
            }
            
            // Start new subject
            $currentSubject = $matches[1];
            $remainder = trim($matches[2]);
            
            // Check if this line ends the statement
            if (substr($remainder, -1) === '.') {
                // Complete statement on one line
                $subjects[$currentSubject] = [$remainder];
                $currentSubject = null;
                $currentStatements = [];
            } else {
                // Multi-line statement
                $currentStatements = [$remainder];
                $inMultiLineStatement = true;
            }
        } else if ($currentSubject && !empty($trimmedLine)) {
            // Continue current subject
            $currentStatements[] = $trimmedLine;
            
            // Check if this line ends the statement
            if (substr($trimmedLine, -1) === '.') {
                $subjects[$currentSubject] = $this->cleanStatements($currentStatements);
                $currentSubject = null;
                $currentStatements = [];
                $inMultiLineStatement = false;
            }
        }
    }
    
    // Don't forget the last subject
    if ($currentSubject && !empty($currentStatements)) {
        $subjects[$currentSubject] = $this->cleanStatements($currentStatements);
    }
    
    return $subjects;
}

/**
 * Clean and standardize SPARQL or triplestore statements.
 *
 * This method processes an array of statements by:
 * - Removing leading and trailing whitespace
 * - Removing trailing punctuation (semicolons and periods)
 * - Normalizing whitespace to single spaces
 * - Removing empty statements
 *
 * @param array $statements Raw statements to be cleaned
 * @return array Cleaned and standardized statements
 */
private function cleanStatements($statements)
{
    $cleaned = [];
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Remove trailing punctuation for consistent formatting
        $statement = rtrim($statement, ';.');
        
        // Ensure proper spacing around predicates and objects
        $statement = preg_replace('/\s+/', ' ', $statement);
        
        if (!empty($statement)) {
            $cleaned[] = $statement;
        }
    }
    
    return $cleaned;
}

/**
 * Formats RDF subject statements into a properly indented Turtle format
 * 
 * This method takes a subject and its associated predicate-object statements,
 * and formats them into a valid Turtle syntax with proper indentation.
 * The method:
 * - Handles empty statement lists
 * - Filters out empty statements
 * - Excludes dct:date statements for download
 * - Properly indents statements (first statement after subject, others with 4-space indentation)
 * - Joins statements with semicolons
 * - Ends the statement group with a period
 *
 * @param string $subject    The RDF subject (typically a URI)
 * @param array $statements  Array of predicate-object statements related to the subject
 * 
 * @return string Formatted Turtle representation of the subject and its statements
 */
private function formatSubjectStatements($subject, $statements)
{
    if (empty($statements)) {
        return $subject . " .\n";
    }
    
    $formatted = $subject;
    
    // Process statements with proper indentation
    $processedStatements = [];
    
    foreach ($statements as $i => $statement) {
        $cleanStatement = trim($statement);
        
        // Skip empty statements
        if (empty($cleanStatement)) {
            continue;
        }

        // Skip dct:date statements for download
        if (strpos($cleanStatement, 'dct:date') === 0) {
            continue;
        }
        
        // Add proper indentation
        if ($i === 0) {
            // First statement goes right after the subject
            $processedStatements[] = " " . $cleanStatement;
        } else {
            // Subsequent statements are indented
            $processedStatements[] = "    " . $cleanStatement;
        }
    }
    
    if (!empty($processedStatements)) {
        $formatted = $subject . implode(" ;\n", $processedStatements) . " .\n";
    } else {
        $formatted = $subject . " .\n";
    }
    
    return $formatted;
}

/**
 * Organizes and formats Turtle (TTL) data with specific structure and sections.
 * 
 * This method takes raw TTL data and transforms it into a structured document
 * with logical sections organized by archaeological entity types. The resulting
 * document includes:
 * - Standard TTL prefixes
 * - A descriptive header with metadata
 * - Categorized sections of archaeological data
 * 
 * The data is organized into sections such as excavation details, locations,
 * GPS coordinates, archaeological items, etc., with each section clearly 
 * labeled for improved readability.
 * 
 * @param string $rawTtlData The raw TTL data to parse and organize
 * @param string|int $itemSetId The ID of the item set being processed
 * @return string Formatted and organized TTL data with sections and headers
 * 
 * @see parseTtlIntoSubjects() Method used to parse TTL into subject groups
 * @see getTtlPrefixes() Method that returns standard TTL prefix declarations
 * @see findSubjectsByPattern() Method to filter subjects by regex pattern
 * @see formatSubjectStatements() Method to format statements for a subject
 */
private function organizeAndFormatTtl($rawTtlData, $itemSetId)
{
    // Parse the TTL data into subject-grouped statements
    $subjects = $this->parseTtlIntoSubjects($rawTtlData);
    
    // Build organized TTL with proper header
    $organizedTtl = $this->getTtlPrefixes();
    $organizedTtl .= "\n# ========================================================================================\n";
    $organizedTtl .= "# ARCHAEOLOGICAL ITEM DATA - ITEM SET $itemSetId\n";
    $organizedTtl .= "# Downloaded from GraphDB on " . date('Y-m-d H:i:s') . "\n";
    $organizedTtl .= "# Organized by resource type for better readability\n";
    $organizedTtl .= "# ========================================================================================\n\n";
    
    // Define logical sections in order
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
 * Filters an array of subjects based on a regular expression pattern.
 *
 * This method searches through all statements for each subject and returns only
 * those subjects whose statements match the provided pattern.
 *
 * @param array $subjects An associative array where keys are subjects and values are arrays of statements
 * @param string $pattern A regular expression pattern to match against the statements
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
 * Removes existing `@prefix` declarations from a given Turtle (TTL) data string.
 *
 * This function is used to clean TTL data by stripping out any `@prefix` directives.
 * This is typically done when a standardized set of prefixes needs to be applied
 * or when the prefixes in the input data are redundant or undesirable.
 *
 * @param string $ttlData The input Turtle data as a string.
 * @return string The TTL data with all `@prefix` declarations removed.
 */
private function cleanExistingPrefixes($ttlData)
{
    // Remove any existing @prefix declarations since we add our own
    $lines = explode("\n", $ttlData);
    $cleanedLines = [];
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        // Skip @prefix lines and empty lines at the beginning
        if (!empty($trimmedLine) && strpos($trimmedLine, '@prefix') !== 0) {
            $cleanedLines[] = $line;
        }
    }
    
    return implode("\n", $cleanedLines);
}


/**
 * Queries the GraphDB for a specific item and constructs its RDF data in Turtle format.
 *
 * This function retrieves an item's data, including its direct properties, related resources
 * (like morphology, chipping, coordinates), associated encounter events, and context resources
 * (location, square, context, SVU). The constructed RDF data is then organized and formatted.
 *
 * @param Omeka_Records_Record_AbstractResource $resource The Omeka resource object representing the item.
 * @param int $itemId The ID of the item to query.
 * @return string|null The organized and formatted TTL data for the item, or null if an error occurs
 * (e.g., no item set found, no identifier found, or query fails).
 */
private function queryItemFromGraphDB($resource, $itemId)
{
    // Get the item set ID to determine the graph
    $itemSetId = null;
    
    // Try to get item set ID
    $itemSets = $resource->itemSets();
    if (!empty($itemSets)) {
        $firstSet = reset($itemSets);
        if ($firstSet) {
            $itemSetId = $firstSet->id();
        }
    }
    
    if (!$itemSetId) {
        error_log("No item set found for item $itemId", 3, OMEKA_PATH . '/logs/download-error.log');
        return null;
    }
    
    $graphUri = "{$this->baseDataGraphUri}{$itemSetId}/";
    
    // Get the item identifier
    $values = $resource->values();
    $identifier = null;
    if (isset($values['dcterms:identifier'])) {
        $identifier = $values['dcterms:identifier']['values'][0]->value();
    }
    
    if (!$identifier) {
        error_log("No identifier found for item $itemId", 3, OMEKA_PATH . '/logs/download-error.log');
        return null;
    }
    
    // Build the item URI pattern
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
        // Organize and format the TTL data for individual item
        $organizedTtl = $this->organizeAndFormatItemTtl($rawTtlData, $identifier, $itemSetId);
        
        error_log("Successfully retrieved and organized item TTL data. Length: " . strlen($organizedTtl), 3, OMEKA_PATH . '/logs/item-download.log');
        return $organizedTtl;
    }
    
    return null;
}

/**
 * Organizes and formats TTL data for archaeological items into a structured, readable format.
 * 
 * This method takes raw Turtle (TTL) data and transforms it into a well-organized document
 * by grouping related statements into logical sections. The output includes a header with
 * item metadata and sections organized by archaeological resource types.
 * 
 * @param string $rawTtlData The raw TTL data to be organized
 * @param string $identifier The identifier of the archaeological item
 * @param string $itemSetId The ID of the item set that contains this item
 * 
 * @return string Formatted and organized TTL data with sections and comments
 * 
 * The method organizes the data into the following sections:
 * - Main archaeological item
 * - Morphology
 * - Chipping
 * - Typometry values
 * - Weight values
 * - Coordinates in square
 * - GPS coordinates
 * - Encounter events
 * - Various reference sections (excavation, location, square, etc.)
 */
private function organizeAndFormatItemTtl($rawTtlData, $identifier, $itemSetId)
{
    // Parse the TTL data into subject-grouped statements
    $subjects = $this->parseTtlIntoSubjects($rawTtlData);
    
    // Build organized TTL
    $organizedTtl = $this->getTtlPrefixes();
    $organizedTtl .= "\n# ========================================================================================\n";
    $organizedTtl .= "# ARCHAEOLOGICAL ITEM DATA - " . strtoupper($identifier) . "\n";
    $organizedTtl .= "# Downloaded from GraphDB on " . date('Y-m-d H:i:s') . "\n";
    $organizedTtl .= "# Item Set: $itemSetId | Item ID: $identifier\n";
    $organizedTtl .= "# Organized by resource type for better readability\n";
    $organizedTtl .= "# ========================================================================================\n\n";
    
    // Define the order of sections for individual items
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
            
            // For the main item, put it first
            if ($sectionKey === 'main_item') {
                $mainItemUri = "http://localhost/megalod/$itemSetId/item/$identifier";
                if (isset($sectionSubjects["<$mainItemUri>"])) {
                    $organizedTtl .= $this->formatSubjectStatements("<$mainItemUri>", $sectionSubjects["<$mainItemUri>"]);
                    unset($sectionSubjects["<$mainItemUri>"]);
                    $organizedTtl .= "\n";
                }
            }
            
            // Then add any other subjects in this section
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
 * Executes a SPARQL CONSTRUCT query against the GraphDB endpoint.
 *
 * This method sends a SPARQL CONSTRUCT query to the configured GraphDB endpoint
 * and returns the result in Turtle (TTL) format. It automatically adds standard
 * TTL prefixes to the response if they're not already included.
 *
 * @param string $query The SPARQL CONSTRUCT query to execute
 * @return string|null The query results in Turtle format, or null if the query fails
 * @throws \Exception Exceptions from the HTTP client are caught and logged
 */
private function executeConstructQuery($query)
{
    try {
        $client = new \Laminas\Http\Client();
        $client->setUri($this->graphdbQueryEndpoint);
        $client->setMethod('POST');
        $client->setHeaders([
            'Content-Type' => 'application/sparql-query',
            'Accept' => 'text/turtle'  // Request TTL format directly
        ]);
        $client->setRawBody($query);
        
        $response = $client->send();
        
        if ($response->isSuccess()) {
            $ttlData = $response->getBody();
            
            // Add prefixes if not included
            if (strpos($ttlData, '@prefix') === false) {
                $ttlData = $this->getTtlPrefixes() . "\n" . $ttlData;
            }
            
            return $ttlData;
        } else {
            error_log('GraphDB query failed: ' . $response->getStatusCode() . ' - ' . $response->getBody(), 3, OMEKA_PATH . '/logs/download-error.log');
            return null;
        }
        
    } catch (\Exception $e) {
        error_log('Error executing GraphDB query: ' . $e->getMessage(), 3, OMEKA_PATH . '/logs/download-error.log');
        return null;
    }
}

/**
 * Sanitizes a filename by removing or replacing invalid characters.
 *
 * This method ensures that filenames only contain alphanumeric characters,
 * underscores, and hyphens. Any other characters are replaced with underscores.
 * Leading and trailing underscores are removed. If the resulting filename is empty,
 * the default name 'download' is used.
 *
 * @param string $filename The original filename to sanitize
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
 * Executes a SPARQL query against a GraphDB endpoint
 * 
 * This method sends a SPARQL query to the configured GraphDB endpoint using HTTP POST
 * and returns the results as a PHP array. It handles the communication with the 
 * triplestore and processes the JSON response.
 *
 * @param string $query The SPARQL query to execute
 * @return array An array of query result bindings, or an empty array if the query fails
 *               or returns no results. Each binding is an associative array representing
 *               a single result row.
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
 * Controller action for the About Us page.
 *
 * This action creates and returns a new ViewModel instance without any specific data.
 * The view will be rendered using the corresponding template for the About Us page.
 *
 * @return \Laminas\View\Model\ViewModel The view model for the About Us page
 */

public function aboutUsAction()
{
    $view = new ViewModel();
    return $view;
}
}