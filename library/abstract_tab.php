<?php

if (! defined('WPINC')) die;

abstract class AbstractTCG {

    protected string $ident;
    
    abstract protected function set_defaults(); // sensible settings for each type of test content
    abstract protected function sanitise(array $input); // also specific to each type
    abstract protected function init_settings(); // admin area ui forms
    
    abstract protected function create(array $options, object|null $progress = null); // called by run() below
    
    
    public function __construct() {
        $this->ident = get_class($this);
        $this->cli = (defined('WP_CLI') and WP_CLI);
        
        // create $this->defaults
        $this->set_defaults();
        
        // pass our defaults/saved options from the db through the sanitiser into $this->options
        $this->sanitise(wp_parse_args(get_option($this->ident, $this->defaults), $this->defaults));
        
        // everything calls run() first to standardise the "sanitise input->make output" process between the web admin and wp-cli
        register_setting($this->ident, $this->ident, array($this, 'run'));
        
        // add the rest of the ui settings particular to that type of test content
        $this->init_settings();
        
        // needed because if this callback is missing, WP wipes the already sanitised value when calling update_option() from the CLI
        add_filter("sanitize_option_{$this->ident}", array(get_called_class(), 'ignore_sanitize_filter'), 10, 3);
    }    
    
    // maybe related to https://wordpress.stackexchange.com/a/298847 ?
    public static function ignore_sanitize_filter($value, $option, $original_value) {
        return (defined('WP_CLI') and WP_CLI) ? $original_value : $value;
    }
    
    
    
    
    public function run(array|null $input = [], bool $save = false): array {
        // detect multiple sanitising passes, for an 11 year old and counting bug: https://core.trac.wordpress.org/ticket/21989
        static $pass_count = 0; $pass_count++; if ($pass_count > 1) return [];
        
        // sanitise our form/cli input
        $this->sanitise($input);
                
        // save it if desired
        if (! $this->cli or $save) update_option($this->ident, $this->options);
        
        // make a progress bar if cli
        $progress = ($this->cli and isset($this->options['amount'])) ? \WP_CLI\Utils\make_progress_bar(
            /* translators: %s: class name of the command */
            sprintf(__('%s: running', 'TestContentGenerator'), $this->ident), $this->options['amount'], 100
        ) : null;
        
        // pass it to the generator
        $this->create($this->options, $progress);
        
        // and return it to WP
        return $this->options;
    }
    
    
    
    // general cli commands across the tab classes
    
    public function show_options() {
        if ($this->cli) {
            WP_CLI::line(sprintf(
                '%s is using these options: %s',
                    get_called_class(),
                    print_r($this->options, 1)
            ));
        }
    }
    
    
    // general utility functions across the tab classes
    
    protected function success(string $message) {
        add_settings_error('TCG_Plugin', 'tcg_okay', $message, 'updated');
        if ($this->cli) WP_CLI::success($message);
    }
    protected function warning(WP_Error|string $message) {
        if (is_a($message, 'WP_Error')) $message = wp_json_encode($message, JSON_PRETTY_PRINT);
        add_settings_error('TCG_Plugin', 'tcg_error', $message, 'error');
        if ($this->cli) WP_CLI::warning($message);
    }
    protected function error(WP_Error|string $message) {
        if (is_a($message, 'WP_Error')) $message = wp_json_encode($message, JSON_PRETTY_PRINT);
        add_settings_error('TCG_Plugin', 'tcg_error', $message, 'error');
        if ($this->cli) WP_CLI::error($message);
    }
    
    
    protected function read_array(array $input, string $key, array $valid_keys): array {
        $array = $this->defaults[$key]; // should check if set and is array here
        
        if (isset($input[$key])) {
            // extra check needed because wp cli can't pass arrays - https://github.com/wp-cli/wp-cli/issues/4616
            if (gettype($input[$key]) == 'string') {
                $input[$key] = explode(',', $input[$key]); // json_decode($input[$key], true); // if you need to get fancier than commas
            }
            if (gettype($input[$key]) == 'array') {
                if (empty(array_filter($input[$key]))) {
                    $array = [];
                } else {
                    $array = array_filter($input[$key], function($k) use ($valid_keys) { return (in_array($k, $valid_keys)); });
                }
            }
        }
        return $array;
    }
    
    
    
    protected function make_options(array $options, string|array $current, bool $simple = true, string|null $option_key = null): string {
        $output = '';
        foreach ($options as $k => $v) {
            if ($simple) {
                $key = $text = $v;
                $match = ($current == $key);
            } else {
                $key = $k;
                $text = (! $option_key or gettype($v) != 'array') ? $v : $v[$option_key];
                $match = ((gettype($current) == 'array' and in_array($key, $current)) or ($key == $current));
            }
            
            $output.= sprintf('<option value="%s"%s>%s</option>', $key, ($match ? ' selected="selected"' : ''), $text);
        }
        return $output;
    }
    
    // https://gist.github.com/angry-dan/e01b8712d6538510dd9c
    protected function natural_language_join(array $list): string {
        $last = array_pop($list);
        return ($list) ? sprintf('%s %s %s', implode(', ', $list), __('and', 'TestContentGenerator'), $last) : $last;
    }
    
    
    
}
