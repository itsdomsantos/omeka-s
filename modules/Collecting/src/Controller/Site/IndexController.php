<?php
namespace Collecting\Controller\Site;

use Collecting\Api\Representation\CollectingFormRepresentation;
use Collecting\MediaType\Manager;
use Omeka\Permissions\Acl;
use Laminas\Mime\Message as MimeMessage;
use Laminas\Mime\Part as MimePart;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    /**
     * @var Acl
     */
    protected $acl;

    protected $mediaTypeManager;

    private $api;


    public function __construct(Acl $acl, Manager $mediaTypeManager)
    {
        $this->acl = $acl;
        $this->mediaTypeManager = $mediaTypeManager;
    }

    public function uploadArrowheadFormAction()
    {
        $formId = $this->params('form-id');
        $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
        $form = $cForm->getForm(); // Get the Laminas Form object

        $view = new ViewModel([
            'form' => $form,
            'formType' => 'arrowhead', // Pass form type to the view
        ]);
        return $view;
    }

// In modules/Collecting/src/Controller/Site/IndexController.php
public function uploadExcavationFormAction()
{
    $formId = 3;
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();

    // Query the triplestore for existing entities (simplified)
    $existingContexts = [
        ['uri' => 'https://purl.org/ah/ms/excavationMS/resource/Context_CTX-001', 'label' => 'CTX-001'],
        ['uri' => 'https://purl.org/ah/ms/excavationMS/resource/Context_CTX-002', 'label' => 'CTX-002']
    ];
    
    $existingSVUs = [
        ['uri' => 'https://purl.org/ah/ms/excavationMS/resource/SVU_SVU-001', 'label' => 'SVU-001'],
        ['uri' => 'https://purl.org/ah/ms/excavationMS/resource/SVU_SVU-002', 'label' => 'SVU-002']
    ];
    
    $existingEncounterEvents = [
        ['uri' => 'https://purl.org/ah/ms/excavationMS/resource/Event_CTX-001', 'label' => '2023-06-15'],
        ['uri' => 'https://purl.org/ah/ms/excavationMS/resource/Event_CTX-002', 'label' => '2023-07-15']
    ];

    $view = new ViewModel([
        'form' => $form,
        'formType' => 'excavation',
        'existingContexts' => $existingContexts,
        'existingSVUs' => $existingSVUs,
        'existingEncounterEvents' => $existingEncounterEvents,
    ]);
    
    return $view;
}

    public function submitArrowheadAction()
    {
        $formId = $this->params('form-id');
        $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
        $form = $cForm->getForm();
        $form->setData($this->params()->fromPost());

        if ($form->isValid()) {
            $arrowheadData = $this->getFormData($cForm); // Extract data (see helper method below)

            //  *** PASS DATA TO YOUR MODULE (ADJUST AS NEEDED)  ***
            $this->redirectToTriplestore($arrowheadData, 'arrowhead');

        } else {
            $this->messenger()->addErrors($form->getMessages());
            return $this->redirect()->toRoute('site/collecting', ['form-id' => $formId, 'action' => 'uploadArrowheadForm']);
        }
    }

    /**
 * Fetch entities of a specific type from the triple store
 * 
 * @param string $entityType The type of entity to fetch (Context, SVU, EncounterEvent)
 * @return array List of entities with their IDs and names
 */
private function fetchEntitiesFromTripleStore($entityType)
{
    // Base SPARQL endpoint
    $endpoint = "http://localhost:7200/repositories/arch-project-shacl";
    
    // Query pattern depends on entity type
    switch ($entityType) {
        case 'Context':
            $query = "
                PREFIX crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>
                PREFIX dct: <http://purl.org/dc/terms/>
                
                SELECT ?context ?id
                WHERE {
                    ?context a crmarchaeo:A1_Excavation_Processing_Unit ;
                             dct:identifier ?id .
                }
                ORDER BY ?id
            ";
            break;
            
        case 'SVU':
            $query = "
                PREFIX crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>
                PREFIX dct: <http://purl.org/dc/terms/>
                
                SELECT ?svu ?id
                WHERE {
                    ?svu a crmarchaeo:A2_Stratigraphic_Volume_Unit ;
                         dct:identifier ?id .
                }
                ORDER BY ?id
            ";
            break;
            
        case 'EncounterEvent':
            $query = "
                PREFIX crmsci: <https://cidoc-crm.org/extensions/crmsci/>
                PREFIX dct: <http://purl.org/dc/terms/>
                
                SELECT ?event ?date
                WHERE {
                    ?event a crmsci:S19_Encounter_Event ;
                           dct:date ?date .
                }
                ORDER BY ?date
            ";
            break;
            
        default:
            return [];
    }
    
    // Use HTTP client to query the triple store
    $client = new \Laminas\Http\Client();
    $client->setUri($endpoint);
    $client->setMethod('POST');
    $client->setParameterPost([
        'query' => $query,
        'format' => 'application/sparql-results+json'
    ]);
    
    $response = $client->send();
    
    if ($response->isSuccess()) {
        $results = json_decode($response->getBody(), true);
        $entities = [];
        
        // Process results based on entity type
        if (isset($results['results']['bindings'])) {
            foreach ($results['results']['bindings'] as $binding) {
                if ($entityType === 'EncounterEvent') {
                    $entities[] = [
                        'uri' => $binding['event']['value'],
                        'label' => $binding['date']['value']
                    ];
                } else {
                    $entities[] = [
                        'uri' => $binding[strtolower($entityType)]['value'],
                        'label' => $binding['id']['value']
                    ];
                }
            }
        }
        
        return $entities;
    }
    
    return [];
}



private function createTtlFromExcavationData($data)
{
    $ttl = "@prefix excav: <https://purl.org/ah/ms/excavationMS#>.\n";
    $ttl .= "@prefix xsd: <http://www.w3.org/2001/XMLSchema#>.\n";
    $ttl .= "@prefix time: <http://www.w3.org/2006/time#>.\n";
    $ttl .= "@prefix dbo: <http://dbpedia.org/ontology/>.\n";
    $ttl .= "@prefix geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>.\n";
    $ttl .= "@prefix sh: <http://www.w3.org/ns/shacl#>.\n";
    $ttl .= "@prefix crm: <http://www.cidoc-crm.org/cidoc-crm/>.\n";
    $ttl .= "@prefix crmsci: <https://cidoc-crm.org/extensions/crmsci/>.\n";
    $ttl .= "@prefix crmarchaeo: <http://www.cidoc-crm.org/extensions/crmarchaeo/>.\n";
    $ttl .= "@prefix edm: <http://www.europeana.eu/schemas/edm#>.\n";
    $ttl .= "@prefix dul: <http://www.ontologydesignpatterns.org/ont/dul/DUL.owl#>.\n";
    $ttl .= "@prefix ah: <http://www.purl.com/ah/ms/ahMS#>.\n";
    $ttl .= "@prefix ah-vocab: <http://www.purl.com/ah/kos#>.\n";
    $ttl .= "@prefix dct: <http://purl.org/dc/terms/>.\n";
    $ttl .= "@prefix foaf: <http://xmlns.com/foaf/0.1/>.\n";
    $ttl .= "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#>.\n";
    $ttl .= "@prefix schema: <http://schema.org/>.\n";
    $ttl .= "@prefix voaf: <http://purl.org/vocommons/voaf#>.\n";
    $ttl .= "@prefix skos: <http://www.w3.org/2004/02/skos/core#>.\n";
    
    return $ttl;
}


public function submitExcavationAction()
{
    $formId = $this->params('form-id');
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();
    $form->setData($this->params()->fromPost());

    if ($form->isValid()) {
        // Capture main form data
        $excavationData = $this->getFormData($cForm);
        
        // Capture additional entity data from POST
        $excavationData['entities'] = [
            // Context handling
            'context' => $this->processEntitySelection(
                $this->params()->fromPost('existing_context'),
                [
                    'id' => $this->params()->fromPost('new_context_id'),
                    'description' => $this->params()->fromPost('new_context_description')
                ],
                'Context'
            ),
            
            // Stratigraphic Volume Unit handling
            'svu' => $this->processEntitySelection(
                $this->params()->fromPost('existing_svu'),
                [
                    'id' => $this->params()->fromPost('new_svu_id'),
                    'description' => $this->params()->fromPost('new_svu_description'),
                    'lower_year' => $this->params()->fromPost('new_svu_lower_year'),
                    'lower_bc' => $this->params()->fromPost('new_svu_lower_bc') ? true : false,
                    'upper_year' => $this->params()->fromPost('new_svu_upper_year'),
                    'upper_bc' => $this->params()->fromPost('new_svu_upper_bc') ? true : false
                ],
                'SVU'
            ),
            
            // Encounter Event handling
            'encounter' => $this->processEntitySelection(
                $this->params()->fromPost('existing_encounter'),
                [
                    'date' => $this->params()->fromPost('new_encounter_date'),
                    'depth' => $this->params()->fromPost('new_encounter_depth')
                ],
                'EncounterEvent'
            )
        ];

        // Create an item set for this excavation
        $identifier = $excavationData['entities']['context']['data']['id'] ?? 
                      $excavationData['entities']['svu']['data']['id'] ?? 
                      $this->params()->fromPost('new_context_id') ?? 
                      $this->params()->fromPost('new_svu_id') ?? 
                      'EXC-' . uniqid();
        
        // Prepare data for item set creation
        $itemSetData = [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    '@value' => "Excavation $identifier"
                ]
            ],
            'dcterms:description' => [
                [
                    'type' => 'literal',
                    '@value' => "Item set for excavation with identifier $identifier"
                ]
            ],
            'o:is_public' => true
        ];

        try {
            // Create the item set
            $itemSetResponse = $this->api()->create('item_sets', $itemSetData);
            $itemSetId = $itemSetResponse->getContent()->id();

            // Redirect to AddTriplestore module with item set ID
            $this->redirectToTriplestore($excavationData, 'excavation', $itemSetId);
        } catch (\Exception $e) {
            // Log the error
            error_log('Failed to create item set: ' . $e->getMessage());
            
            // Redirect without item set
            $this->redirectToTriplestore($excavationData, 'excavation');
        }
    } else {
        $this->messenger()->addErrors($form->getMessages());
        return $this->redirect()->toRoute('site/collecting', ['form-id' => $formId, 'action' => 'uploadExcavationForm']);
    }
}

/**
 * Process entity selection - either return existing URI or create new entity data
 */
private function processEntitySelection($existingUri, array $newData, $entityType)
{
    if (!empty($existingUri)) {
        return ['uri' => $existingUri, 'isExisting' => true];
    }
    
    // Check if we have the minimum required data to create a new entity
    switch ($entityType) {
        case 'Context':
            if (empty($newData['id'])) {
                return null;
            }
            break;
        case 'SVU':
            if (empty($newData['id'])) {
                return null;
            }
            break;
        case 'EncounterEvent':
            if (empty($newData['date'])) {
                return null;
            }
            break;
    }
    
    return ['data' => $newData, 'isExisting' => false, 'type' => $entityType];
}

/**
 * Redirect to the AddTriplestore module with data and optional item set ID
 */
private function redirectToTriplestore(array $data, string $uploadType, ?int $itemSetId = null): void
{
    $query = ['form_data' => $data, 'upload_type' => $uploadType];
    if ($itemSetId) {
        $query['item_set_id'] = $itemSetId;
    }
    
    $url = $this->url('site/add-triplestore/upload', ['site-slug' => $this->currentSite()->slug()], true);
    $url .= '?' . http_build_query($query);
    
    $this->redirect()->toUrl($url);
}

    /**
     * Helper method to extract and format form data.
     * Adapt this to your specific form structure!
     */
    private function getFormData(CollectingFormRepresentation $cForm): array
    {
        $formData = [];
        foreach ($cForm->prompts() as $prompt) {
            $fieldName = 'prompt_' . $prompt->id();  //  Example:  How names are generated
            if (isset($this->params()->fromPost()[$fieldName])) {
                $formData[$prompt->type()] = $this->params()->fromPost()[$fieldName];
            }
        }
        return $formData;
    }


    public function submitAction()
{
    if (!$this->getRequest()->isPost()) {
        return $this->redirect()->toRoute('site', [], true);
    }

    $cForm = $this->api()
        ->read('collecting_forms', $this->params('form-id'))
        ->getContent();

    $form = $cForm->getForm();
    $form->setData($this->params()->fromPost());
    if ($form->isValid()) {
        [$itemData, $cItemData] = $this->getPromptData($cForm);

        // Temporarily give the user permission to create the Omeka and
        // Collecting items. This gives all roles all privileges to all
        // resources, which _should_ be safe since we're only passing
        // mediated data.
        $this->acl->allow();
        // Allow the can-assign-items privilege so the IndexController can
        // assign the current o:site to the item. This is needed becuase,
        // for some reason, the ACL does not ignore can-assign-items, even
        // with the above allow().
        $this->acl->allow(null, 'Omeka\Entity\Site', 'can-assign-items');

        // Create the Omeka item.
        $itemData['o:is_public'] = false;
        $itemData['o:item_set'] = [
            'o:id' => $cForm->itemSet() ? $cForm->itemSet()->id() : null,
        ];
        // Nothing needs to be done for the default site assignment. The
        // item adapter will automatically assign the proper sites.
        if (!$cForm->defaultSiteAssign()) {
            // Otherwise, assign the current site only.
            $itemData['o:site'] = [
                'o:id' => $this->currentSite()->id(),
            ];
        }
        $response = $this->api($form)
            ->create('items', $itemData, $this->params()->fromFiles());

        if ($response) {
            $item = $response->getContent();

            // Create the Collecting item.
            $cItemData['o:item'] = ['o:id' => $item->id()];
            $cItemData['o-module-collecting:form'] = ['o:id' => $cForm->id()];

            if ('user' === $cForm->anonType()) {
                // If the form has the "user" anonymity type, the item's
                // defualt anonymous flag is "false" becuase the related
                // prompt ("User Public") is naturally public.
                $cItemData['o-module-collecting:anon']
                    = $this->params()->fromPost(sprintf('anon_%s', $cForm->id()), false);
            }

            $response = $this->api($form)->create('collecting_items', $cItemData);

            if ($response) {
                $cItem = $response->getContent();

                // Send a submission email if the user opts-in and provides
                // an email address.
                $sendEmail = $this->params()->fromPost(sprintf('email_send_%s', $cForm->id()), false);
                if ($sendEmail && $cItem->userEmail()) {
                    $this->sendSubmissionEmail($cForm, $cItem);
                }
                // Send a notification email if configured to do so.
                $sendEmailNotify = $this->siteSettings()->get('collecting_email_notify');
                if ($sendEmailNotify) {
                    $this->sendNotificationEmail($cForm, $cItem);
                }

                return $this->redirect()->toRoute(null, ['action' => 'success'], true);
            }
        }

        // Out of an abundance of caution, revert back to default permissions.
        $this->acl->removeAllow();
    } else {
        $this->messenger()->addErrors($form->getMessages());
    }

    $view = new ViewModel;
    $view->setVariable('cForm', $cForm);
    return $view;
}

    public function successAction()
    {
        $cForm = $this->api()
            ->read('collecting_forms', $this->params('form-id'))
            ->getContent();
        $view = new ViewModel;
        $view->setVariable('cForm', $cForm);
        return $view;
    }

    public function tosAction()
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        $response->setContent($this->siteSettings()->get('collecting_tos'));
        return $response;
    }

    public function itemShowAction()
    {
        if ($this->siteSettings()->get('collecting_hide_collected_data')) {
            // Don't render the page if configured to hide it.
            return $this->redirect()->toRoute('site', [], true);
        }
        $site = $this->currentSite();
        $cItem = $this->api()
            ->read('collecting_items', $this->params('item-id'))->getContent();

        $view = new ViewModel;
        $view->setVariable('site', $site);
        $view->setVariable('cItem', $cItem);
        return $view;
    }

    /**
     * Get the prompt data needed to create the Omeka and Collecting items.
     *
     * @param CollectingFormRepresentation $cForm
     * @return array [itemData, cItemData]
     */
    protected function getPromptData(CollectingFormRepresentation $cForm)
    {
        // Derive the prompt IDs from the form names.
        $postedPrompts = [];
        foreach ($this->params()->fromPost() as $key => $value) {
            if (preg_match('/^prompt_(\d+)$/', $key, $matches)) {
                $postedPrompts[$matches[1]] = $value;
            }
        }

        $itemData = [];
        $cItemData = [];
        $inputData = [];

        // Note that we're iterating the known prompts, not the ones submitted
        // with the form. This way we accept only valid prompts.
        foreach ($cForm->prompts() as $prompt) {
            if (!isset($postedPrompts[$prompt->id()])) {
                // This prompt was not found in the POSTed data.
                continue;
            }
            switch ($prompt->type()) {
                case 'property':
                    switch ($prompt->inputType()) {
                        case 'url':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'uri',
                                'property_id' => $prompt->property()->id(),
                                '@id' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'item':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'resource',
                                'property_id' => $prompt->property()->id(),
                                'value_resource_id' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:timestamp':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:timestamp',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:interval':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:interval',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:duration':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:duration',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        case 'numeric:integer':
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'numeric:integer',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                            break;
                        default:
                            $itemData[$prompt->property()->term()][] = [
                                'type' => 'literal',
                                'property_id' => $prompt->property()->id(),
                                '@value' => $postedPrompts[$prompt->id()],
                            ];
                    }
                    // Note that there's no break here. We need to save all
                    // property types as inputs so the relationship between the
                    // prompt and the user input isn't lost.
                case 'input':
                case 'user_private':
                case 'user_public':
                    // Do not save empty inputs.
                    if ('' !== trim($postedPrompts[$prompt->id()])) {
                        $inputData[] = [
                            'o-module-collecting:prompt' => $prompt->id(),
                            'o-module-collecting:text' => $postedPrompts[$prompt->id()],
                        ];
                    }
                    break;
                case 'user_name':
                    $cItemData['o-module-collecting:user_name'] = $postedPrompts[$prompt->id()];
                    break;
                case 'user_email':
                    $cItemData['o-module-collecting:user_email'] = $postedPrompts[$prompt->id()];
                    break;
                case 'media':
                    $itemData = $this->mediaTypeManager->get($prompt->mediaType())
                        ->itemData($itemData, $postedPrompts[$prompt->id()], $prompt);
                    break;
                default:
                    // Invalid prompt type. Do nothing.
                    break;
            }
        }

        $cItemData['o-module-collecting:input'] = $inputData;
        return [$itemData, $cItemData];
    }

    /**
     * Send a submission email.
     *
     * @param CollectingFormRepresentation $cForm
     * @param CollectingItemRepresentation $cItem
     */
    protected function sendSubmissionEmail($cForm, $cItem)
    {
        $i18nHelper = $this->viewHelpers()->get('i18n');
        $partialHelper = $this->viewHelpers()->get('partial');

        $messageContent = '';
        if ($cForm->emailText()) {
            $messageContent .= $cForm->emailText();
        }
        $messageContent .= sprintf(
            '<p>You submitted the following data on %s using the form “%s” on the site “%s”: %s</p>',
            $i18nHelper->dateFormat($cItem->item()->created(), 'long'),
            $cItem->form()->label(),
            $cItem->form()->site()->title(),
            $cItem->form()->site()->siteUrl(null, true)
        );
        $messageContent .= $partialHelper('common/collecting-item-inputs', ['cItem' => $cItem]);
        $messageContent .= '<p>(All data you submitted was saved, even if you do not see it here.)</p>';

        $messagePart = new MimePart($messageContent);
        $messagePart->setType('text/html');
        $messagePart->setCharset('UTF-8');

        $body = new MimeMessage;
        $body->addPart($messagePart);

        $options = [];
        $from = $this->siteSettings()->get('collecting_email');
        if ($from) {
            $options['from'] = $from;
        }
        $message = $this->mailer()->createMessage($options)
            ->addTo($cItem->userEmail(), $cItem->userName())
            ->setSubject($this->translate('Thank you for your submission'))
            ->setBody($body);
        $this->mailer()->send($message);
    }

    /**
     * Send a notification email.
     *
     * @param CollectingFormRepresentation $cForm
     * @param CollectingItemRepresentation $cItem
     */
    protected function sendNotificationEmail($cForm, $cItem)
    {
        $i18nHelper = $this->viewHelpers()->get('i18n');
        $partialHelper = $this->viewHelpers()->get('partial');
        $urlHelper = $this->viewHelpers()->get('url');

        $messageContent = '';
        if ($cForm->emailText()) {
            $messageContent .= $cForm->emailText();
        }
        $messageContent .= sprintf(
            '<p>A user submitted the following data on %s using the form “%s” on the site “%s”: %s</p>',
            $i18nHelper->dateFormat($cItem->item()->created(), 'long'),
            $cItem->form()->label(),
            $cItem->form()->site()->title(),
            $cItem->form()->site()->siteUrl(null, true)
        );
        $messageContent .= $partialHelper('common/collecting-item-inputs', ['cItem' => $cItem]);
        $messageContent .= sprintf(
            '<p><a href="%s">%s</a></p>',
            $urlHelper('admin/site/slug/collecting/item', ['item-id' => $cItem->id()], ['force_canonical' => true], true),
            'Go here to administer the submitted item.'
        );

        $messagePart = new MimePart($messageContent);
        $messagePart->setType('text/html');
        $messagePart->setCharset('UTF-8');

        $body = new MimeMessage;
        $body->addPart($messagePart);

        $options = [];
        $from = $this->siteSettings()->get('collecting_email');
        $to = $this->siteSettings()->get('collecting_email_notify');
        if ($from) {
            $options['from'] = $from;
        }
        $message = $this->mailer()->createMessage($options)
            ->addTo($to)
            ->setSubject($this->translate('Collecting submission notification'))
            ->setBody($body);
        $this->mailer()->send($message);
    }
}
