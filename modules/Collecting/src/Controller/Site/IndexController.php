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


/**
 * This action displays the form for uploading arrowheads.
 * @return ViewModel
 */
public function uploadArrowheadFormAction()
{
    $formId = 4; // id of the arrowhead form
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();
    
    $itemSetId = $this->params()->fromQuery('item_set_id');
    $uploadType = $this->params()->fromQuery('upload_type', 'arrowhead');
    $returnUrl = $this->params()->fromQuery('return_url');
    
    $squares = [];
    $contexts = [];
    $svus = [];
    
    if ($itemSetId) {
        // Fetch all items in this item set
        $items = $this->api()->search('items', ['item_set_id' => $itemSetId])->getContent();
        
        foreach ($items as $item) {
            // Get item type from title or class
            $title = $item->displayTitle();
            $values = $item->values();
            
            if (strpos($title, 'Encounter Event') !== false || 
                strpos($title, 'Archaeological Encounter') !== false ||
                $this->hasProperty($values, 'Encounter Date') ||
                $this->hasProperty($values, 'Encountered Objects')) {
                continue; // Skip encounter events
            }
            
            if ((strpos($title, 'Square') !== false && strpos($title, 'Encounter') === false) ||
                $this->hasProperty($values, 'Square ID')) {
                $squares[] = [
                    'id' => $item->id(),
                    'label' => $title,
                    'identifier' => $this->getPropertyValue($values, 'Square ID')
                ];
            }
            
            if ((strpos($title, 'Context') !== false && strpos($title, 'Encounter') === false) ||
                $this->hasProperty($values, 'Context ID')) {
                $contexts[] = [
                    'id' => $item->id(),
                    'label' => $title,
                    'identifier' => $this->getPropertyValue($values, 'Context ID')
                ];
            }
            
            if (((strpos($title, 'Stratigraphic Unit') !== false || 
                  strpos($title, 'Stratigraphic Volume Unit') !== false ||
                  (strpos($title, 'SVU') !== false && strpos($title, 'Encounter') === false)) &&
                 strpos($title, 'Encounter Event') === false) ||
                $this->hasProperty($values, 'SVU ID')) {
                $svus[] = [
                    'id' => $item->id(),
                    'label' => $title,
                    'identifier' => $this->getPropertyValue($values, 'SVU ID')
                ];
            }
        }
    }
    
    if ($returnUrl) {
        $form->setAttribute('action', $this->url()->fromRoute('site/add-triplestore/process-collecting', [
            'site-slug' => $this->currentSite()->slug(),
        ], [
            'query' => [
                'item_set_id' => $itemSetId,
                'upload_type' => $uploadType
            ]
        ]));
    }
    
    $result = $this->params()->fromQuery('result', '');
    
    $view = new ViewModel([
        'form' => $form,
        'formType' => 'arrowhead',
        'itemSetId' => $itemSetId,
        'result' => $result,
        'squares' => $squares,
        'contexts' => $contexts,
        'svus' => $svus
    ]);
    
    return $view;
}
    
/**
 * This method checks if the provided values contain a specific property label.
 * @param mixed $values
 * @param mixed $propertyLabel
 * @return bool
 */
private function hasProperty($values, $propertyLabel)
{
    if (empty($values)) {
        return false;
    }
    
    foreach ($values as $propertyValues) {
        if (!isset($propertyValues[0])) {
            continue;
        }
        
        // Get the property
        $property = $propertyValues[0]->property();
        if (!$property) {
            continue;
        }
        
        if ($property->label() === $propertyLabel) {
            return true;
        }
    }
    return false;
}

/**
 * This method retrieves the value of a specific property from the provided values.
 * @param mixed $values
 * @param mixed $propertyLabel
 */
private function getPropertyValue($values, $propertyLabel)
{
    if (empty($values)) {
        return null;
    }
    
    foreach ($values as $propertyValues) {
        if (!isset($propertyValues[0])) {
            continue;
        }
        
        // Get the property
        $property = $propertyValues[0]->property();
        if (!$property) {
            continue;
        }
        
        if ($property->label() === $propertyLabel) {
            return $propertyValues[0]->value();
        }
    }
    return null;
}



/**
 * This method displays the form for uploading excavation data.
 * @return ViewModel
 */
public function uploadExcavationFormAction()
{
    $formId = 3; // id of the excavation form
    $cForm = $this->api()->read('collecting_forms', $formId)->getContent();
    $form = $cForm->getForm();

    $existingArchaeologists = [];
    
    $result = $this->params()->fromQuery('result');
    $itemSetId = $this->params()->fromQuery('item_set_id');

    $view = new ViewModel([
        'form' => $form,
        'formType' => 'excavation',
        'existingArchaeologists' => $existingArchaeologists,
        'result' => $result,
        'itemSetId' => $itemSetId
    ]);
    
    return $view;
}



    /**
     * This method handles the form submission for Collecting items.
     * @return ViewModel|\Laminas\Http\Response
     */
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

        
        $this->acl->allow();
       
        $this->acl->allow(null, 'Omeka\Entity\Site', 'can-assign-items');

        $itemData['o:is_public'] = false;
        $itemData['o:item_set'] = [
            'o:id' => $cForm->itemSet() ? $cForm->itemSet()->id() : null,
        ];
        if (!$cForm->defaultSiteAssign()) {
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
                $cItemData['o-module-collecting:anon']
                    = $this->params()->fromPost(sprintf('anon_%s', $cForm->id()), false);
            }

            $response = $this->api($form)->create('collecting_items', $cItemData);

            if ($response) {
                $cItem = $response->getContent();

                $sendEmail = $this->params()->fromPost(sprintf('email_send_%s', $cForm->id()), false);
                if ($sendEmail && $cItem->userEmail()) {
                    $this->sendSubmissionEmail($cForm, $cItem);
                }
                $sendEmailNotify = $this->siteSettings()->get('collecting_email_notify');
                if ($sendEmailNotify) {
                    $this->sendNotificationEmail($cForm, $cItem);
                }

                return $this->redirect()->toRoute(null, ['action' => 'success'], true);
            }
        }

        $this->acl->removeAllow();
    } else {
        $this->messenger()->addErrors($form->getMessages());
    }

    $view = new ViewModel;
    $view->setVariable('cForm', $cForm);
    return $view;
}

    /**
     * This method displays the success page after a successful submission.
     * @return ViewModel
     */
    public function successAction()
    {
        $cForm = $this->api()
            ->read('collecting_forms', $this->params('form-id'))
            ->getContent();
        $view = new ViewModel;
        $view->setVariable('cForm', $cForm);
        return $view;
    }

    /**
     * This method displays the terms of service for Collecting.
     * @return \Laminas\Stdlib\ResponseInterface
     */
    public function tosAction()
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        $response->setContent($this->siteSettings()->get('collecting_tos'));
        return $response;
    }

    /**
     * This method displays the item page for a Collecting item.
     * @return ViewModel|\Laminas\Http\Response
     */
    public function itemShowAction()
    {
        if ($this->siteSettings()->get('collecting_hide_collected_data')) {
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
        $postedPrompts = [];
        foreach ($this->params()->fromPost() as $key => $value) {
            if (preg_match('/^prompt_(\d+)$/', $key, $matches)) {
                $postedPrompts[$matches[1]] = $value;
            }
        }

        $itemData = [];
        $cItemData = [];
        $inputData = [];

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
                case 'input':
                case 'user_private':
                case 'user_public':
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
