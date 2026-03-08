class Registrar_Adapter_Enom extends Registrar_AdapterAbstract
{
    public function __construct($options)
    {
        if(isset($options['uid'])) {
            $this->uid = $options['uid'];
        }
        if(isset($options['pw'])) {
            $this->pw = $options['pw'];
        }
        // Toggle between resellertest.enom.com and www.enom.com
        $this->test_mode = isset($options['test_mode']) && $options['test_mode'];
    }

    // More to follow
}
