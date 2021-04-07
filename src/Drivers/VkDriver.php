<?php


namespace VkBotMan\Drivers;


use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Interfaces\UserInterface;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Users\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Outgoing\Question;
use VK\Client\VKApiClient;

/**
 * Class VkDriver
 * @package VkBotMan\Drivers
 */
class VkDriver extends HttpDriver implements VerifiesService
{
    // TODO make config parameters from those const-s
    const DRIVER_NAME = 'Vk';
    const API_VERSION = '5.85';
    const API_URL = 'https://api.vk.com/method/';
    const CONFIRMATION_EVENT = 'confirmation';
    const MESSAGE_EDIT_EVENT = 'message_edit';
    const MESSAGE_NEW_EVENT = 'message_new';
    const EVENTS = [
        'confirmation',
        'message_edit',
        'message_new',
    ];
    /**
     * @var string
     */
    protected $endpoint = 'messages.send';
    /**
     * @var array
     */
    protected $messages = [];

    /**
     * @var Collection
     */
    protected $queryParameters;

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {

        if (!is_null($this->event->get('text')) && count($this->event->get('attachments')) === 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function types(IncomingMessage $matchingMessage)
    {
        //TODO VK_GROUP_ACCESS_TOKEN in config
        $parameters = [
            'type' => 'typing',
            'peer_id' => $matchingMessage->getSender(),
            'access_token' => $this->config->get('token'),
            'v' => self::API_VERSION,
        ];

        return $this->http->post($this->buildApiUrl('messages.setActivity'), [], $parameters);
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        if ($this->payload->get('type') === self::MESSAGE_NEW_EVENT) {

            $this->messages = [
                new IncomingMessage(
                    $this->event->get('text'),
                    $this->event->get('from_id'),
                    $this->payload->get('group_id'),
                    $this->event)
            ];
        }
       

        if (count($this->messages) === 0) {
            $this->messages = [new IncomingMessage('', '', '')];
        }
        return $this->messages;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config->get('token'))
            && !empty($this->config->get('group_id'))
            && !empty($this->config->get('verification'));
    }

    /**
     * Retrieve User information.
     * @param IncomingMessage $matchingMessage
     * @return UserInterface
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        return new User($matchingMessage->getSender());
    }

    /**
     * @param IncomingMessage $message
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        $payload = $this->event->get('payload');

        if (null !== $payload) {
            $p = json_decode($this->event->get('payload'), true);

            if (isset($p['button'])) {
                $value = $p['button'];

                return Answer::create($value)
                    ->setInteractiveReply(true)
                    ->setMessage($message)
                    ->setValue($value);
            }
        }

        return Answer::create($message->getText())->setMessage($message);
    }

    public function verifyRequest(Request $request)
    {
        $request_array = $request->toArray();
        if (
            isset($request_array['type'])
            && ($request_array['type'] == 'confirmation')
            && isset($request_array['group_id'])
            && ($request_array['group_id'] == $this->config->get('group_id'))
        ) {
            return Response::create($this->config->get('verification'))->send();
        }
    }


    /**
     * @param string|\BotMan\BotMan\Messages\Outgoing\Question $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return $this
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        //TODO VK_GROUP_ACCESS_TOKEN in config
        $parameters = array_merge_recursive([
            'peer_id' => $matchingMessage->getSender(),
            'access_token' => $this->config->get('token'),
            'v' => self::API_VERSION,
        ], $additionalParameters);

        if ($message instanceof Question) {
            $parameters['message'] = $message->getText();
            $parameters['keyboard'] = json_encode([
                'one_time' => true,
                'buttons' => $this->convertQuestion($message)
            ], JSON_UNESCAPED_UNICODE);

        } elseif ($message instanceof OutgoingMessage) {


            $vk = new VKApiClient('5.101');
      
            if ($message->getAttachment() !== null) {
                $attachment = $message->getAttachment();
                $parameters['caption'] = $message->getText();
                if ($attachment instanceof \BotMan\BotMan\Messages\Attachments\Image) {
                    $temp_file = tempnam(sys_get_temp_dir(), 'Tux') . '.jpg';

                    file_put_contents(
                        $temp_file,
                        file_get_contents($attachment->getUrl())
                    );
                    try {
                    $address = $vk->photos()->getMessagesUploadServer($this->config->get('token'));
                    $photo = $vk->getRequest()->upload($address['upload_url'], 'photo', $temp_file);

                    $response_save_photo = $vk->photos()->saveMessagesPhoto($this->config->get('token'), [
                        'server' => $photo['server'],
                        'photo' => $photo['photo'],
                        'hash' => $photo['hash'],
                    ]);

                    $parameters['attachment'] = 'photo' . $response_save_photo[0]['owner_id'] . '_' . $response_save_photo[0]['id'];

                    unlink($temp_file);
                    } catch (Exception $ex) {
                    } catch (VKApiMessagesDenySendException | VKApiException | VKClientException $e) {
                    } finally {
                        unlink($temp_file);
                    }
                } elseif ($attachment instanceof \BotMan\BotMan\Messages\Attachments\Video) {
                    $this->endpoint = 'sendVideo';
                    $parameters['video'] = $attachment->getUrl();
                } elseif ($attachment instanceof \BotMan\BotMan\Messages\Attachments\Audio) {
                    $this->endpoint = 'sendAudio';
                    $parameters['audio'] = $attachment->getUrl();
                } elseif ($attachment instanceof \BotMan\BotMan\Messages\Attachments\File) {
                    $this->endpoint = 'sendDocument';
                    $parameters['document'] = $attachment->getUrl();
                } elseif ($attachment instanceof \BotMan\BotMan\Messages\Attachments\Location) {
                    $this->endpoint = 'sendLocation';
                    $parameters['latitude'] = $attachment->getLatitude();
                    $parameters['longitude'] = $attachment->getLongitude();
                    if (isset($parameters['title'], $parameters['address'])) {
                        $this->endpoint = 'sendVenue';
                    }
                }
            }


            $parameters['message'] = $message->getText();
        } else {
            $parameters['message'] = $message;
        }


        return $parameters;
    }

    /**
     * Convert a Question object into a valid
     * quick reply response object.
     *
     * @param \BotMan\BotMan\Messages\Outgoing\Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $replies = Collection::make($question->getButtons())->map(function ($button) {

            return [
                array_merge([
                    'action' => $button['action'],
                    'color' => $button['color'],
                ], $button['additional']),
            ];
        });

        return $replies->toArray();
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        $response = $this->http->post($this->buildApiUrl($this->endpoint), [], $payload);

        return $response;
    }

    /**
     * @param $endpoint
     * @return string
     */
    protected function buildApiUrl($endpoint)
    {
        return self::API_URL . $endpoint;
    }

    /**
     * @param Request $request
     * @return void
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array)json_decode($request->getContent(), true));

        $this->event = Collection::make($this->payload->get('object'));

        $this->config = Collection::make($this->config->get('vk'));
        $this->queryParameters = Collection::make($request->query);
        $this->content = $request->getContent();
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $parameters = array_replace_recursive([
            'user_id' => $matchingMessage->getSender(),
            'access_token' => $this->config->get('token'),
            'v' => self::API_VERSION,
        ], $parameters);

        $response = $this->http->post($this->buildApiUrl($endpoint), [], $parameters);

        return $response;
    }
}