<?php

namespace VkBotMan\Extensions;

/**
 * Class MessageParameters.
 */
class MessageParameters implements \JsonSerializable
{

    private $parameters;

    public function set_message($message){
        $this->parameters['message'] = $message;
    }

    public function set_array($values)
    {
        foreach ($values as $key => $value){
            $this->parameters[$key] = $value;
        }
    }

    public function toArray()
    {
        return $this->parameters;
    }

    public function jsonSerialize()
    {
        return json_encode($this->toArray());
    }

}
