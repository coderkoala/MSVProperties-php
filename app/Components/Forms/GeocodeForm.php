<?php  namespace App\Components\Forms;

use App\Components\Validation\FormValidator;

class GeocodeForm extends FormValidator
{
    protected $rules = array(
        'leadid' => array('required' => null )
    );

    protected $messages = array(
        'leadid' => array(
            'required' => 'The UUID is required for the desired Lead in Dynamics CRM 2016.',
        )
    );
}