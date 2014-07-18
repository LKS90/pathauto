<?php

/**
 * @file
 * Contains \Drupal\pathauto\PathautoManager.
 */

namespace Drupal\pathauto;

use Drupal\Component\Utility\String;
use \Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Utility\Token;

class PathautoManager {

  /**
   * Calculated settings cache.
   *
   * @todo Split this up into separate properties.
   *
   * @var array
   */
  protected $cleanStringCache = array();

  /**
   * Punctuation characters cache.
   *
   * @var array
   */
  protected $punctuationCharacters = array();

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Language manager.
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Calculated patterns for entities.
   *
   * @var array
   */
  protected $patterns = array();

  /**
   * The alias cleaner.
   *
   * @var \Drupal\pathauto\AliasCleanerInterface
   */
  protected $aliasCleaner;

  /**
   * The alias storage helper.
   *
   * @var \Drupal\pathauto\AliasStorageHelperInterface
   */
  protected $aliasStorageHelper;

  /**
   * The alias uniquifier.
   *
   * @var \Drupal\pathauto\AliasUniquifierInterface
   */
  protected $aliasUniquifier;

  /**
   * Creates a new Pathauto manager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Utility\Token $token
   *   The token utility.
   * @param \Drupal\pathauto\AliasCleanerInterface $alias_cleaner
   *   The alias cleaner.
   * @param \Drupal\pathauto\AliasStorageHelperInterface $alias_storage_helper
   *   The alias storage helper.
   * @param AliasUniquifierInterface $alias_uniquifier
   *   The alias uniquifier.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LanguageManagerInterface $language_manager, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler, Token $token, AliasCleanerInterface $alias_cleaner, AliasStorageHelperInterface $alias_storage_helper, AliasUniquifierInterface $alias_uniquifier) {
    $this->configFactory = $config_factory;
    $this->languageManager = $language_manager;
    $this->cacheBackend = $cache_backend;
    $this->moduleHandler = $module_handler;
    $this->token = $token;
    $this->aliasCleaner = $alias_cleaner;
    $this->aliasStorageHelper = $alias_storage_helper;
    $this->aliasUniquifier = $alias_uniquifier;
  }

  /**
   * Clean up a string segment to be used in an URL alias.
   *
   * Performs the following possible alterations:
   * - Remove all HTML tags.
   * - Process the string through the transliteration module.
   * - Replace or remove punctuation with the separator character.
   * - Remove back-slashes.
   * - Replace non-ascii and non-numeric characters with the separator.
   * - Remove common words.
   * - Replace whitespace with the separator character.
   * - Trim duplicate, leading, and trailing separators.
   * - Convert to lower-case.
   * - Shorten to a desired length and logical position based on word boundaries.
   *
   * This function should *not* be called on URL alias or path strings
   * because it is assumed that they are already clean.
   *
   * @param string $string
   *   A string to clean.
   * @param array $options
   *   (optional) A keyed array of settings and flags to control the Pathauto
   *   clean string replacement process. Supported options are:
   *   - langcode: A language code to be used when translating strings.
   *
   * @return string
   *   The cleaned string.
   */
  public function cleanString($string, array $options = array()) {
    if (empty($this->cleanStringCache)) {
      // Generate and cache variables used in this method.
      $config = $this->configFactory->get('pathauto.settings');
      $this->cleanStringCache = array(
        'separator' => $config->get('separator'),
        'strings' => array(),
        'transliterate' => $config->get('transliterate'),
        'punctuation' => array(),
        'reduce_ascii' => (bool) $config->get('reduce_ascii'),
        'ignore_words_regex' => FALSE,
        'lowercase' => (bool) $config->get('case'),
        'maxlength' => min($config->get('max_component_length'), $this->aliasStorageHelper->getAliasSchemaMaxLength()),
      );

      // Generate and cache the punctuation replacements for strtr().
      $punctuation = $this->getPunctuationCharacters();
      foreach ($punctuation as $name => $details) {
        $action = $config->get('punctuation_' . $name);
        switch ($action) {
          case PATHAUTO_PUNCTUATION_REMOVE:
            $cache['punctuation'][$details['value']] = '';
            $this->cleanStringCache;

          case PATHAUTO_PUNCTUATION_REPLACE:
            $this->cleanStringCache['punctuation'][$details['value']] = $this->cleanStringCache['separator'];
            break;

          case PATHAUTO_PUNCTUATION_DO_NOTHING:
            // Literally do nothing.
            break;
        }
      }

      // Generate and cache the ignored words regular expression.
      $ignore_words = $config->get('ignore_words');
      $ignore_words_regex = preg_replace(array('/^[,\s]+|[,\s]+$/', '/[,\s]+/'), array('', '\b|\b'), $ignore_words);
      if ($ignore_words_regex) {
        $this->cleanStringCache['ignore_words_regex'] = '\b' . $ignore_words_regex . '\b';
        if (function_exists('mb_eregi_replace')) {
          $this->cleanStringCache['ignore_words_callback'] = 'mb_eregi_replace';
        }
        else {
          $this->cleanStringCache['ignore_words_callback'] = 'preg_replace';
          $this->cleanStringCache['ignore_words_regex'] = '/' . $this->cleanStringCache['ignore_words_regex'] . '/i';
        }
      }
    }

    // Empty strings do not need any proccessing.
    if ($string === '' || $string === NULL) {
      return '';
    }

    $langcode = NULL;
    if (!empty($options['language']->language)) {
      $langcode = $options['language']->language;
    }
    elseif (!empty($options['langcode'])) {
      $langcode = $options['langcode'];
    }

    // Check if the string has already been processed, and if so return the
    // cached result.
    if (isset($this->cleanStringCache['strings'][$langcode][$string])) {
      return $this->cleanStringCache['strings'][$langcode][$string];
    }

    // Remove all HTML tags from the string.
    $output = strip_tags(String::decodeEntities($string));

    // Optionally transliterate.
    if ($this->cleanStringCache['transliterate']) {
      // If the reduce strings to letters and numbers is enabled, don't bother
      // replacing unknown characters with a question mark. Use an empty string
      // instead.
      $output = \Drupal::service('transliteration')->transliterate($output, $this->cleanStringCache['reduce_ascii'] ? '' : '?', $langcode);
    }

    // Replace or drop punctuation based on user settings.
    $output = strtr($output, $this->cleanStringCache['punctuation']);

    // Reduce strings to letters and numbers.
    if ($this->cleanStringCache['reduce_ascii']) {
      $output = preg_replace('/[^a-zA-Z0-9\/]+/', $this->cleanStringCache['separator'], $output);
    }

    // Get rid of words that are on the ignore list.
    if ($this->cleanStringCache['ignore_words_regex']) {
      $words_removed = $this->cleanStringCache['ignore_words_callback']($this->cleanStringCache['ignore_words_regex'], '', $output);
      if (Unicode::strlen(trim($words_removed)) > 0) {
        $output = $words_removed;
      }
    }

    // Always replace whitespace with the separator.
    $output = preg_replace('/\s+/', $this->cleanStringCache['separator'], $output);

    // Trim duplicates and remove trailing and leading separators.
    $output = $this->aliasCleaner->getCleanSeparators($this->aliasCleaner->getCleanSeparators($output, $this->cleanStringCache['separator']));

    // Optionally convert to lower case.
    if ($this->cleanStringCache['lowercase']) {
      $output = Unicode::strtolower($output);
    }

    // Shorten to a logical place based on word boundaries.
    $output = Unicode::truncate($output, $this->cleanStringCache['maxlength'], TRUE);

    // Cache this result in the static array.
    $this->cleanStringCache['strings'][$langcode][$string] = $output;

    return $output;
  }


  /**
   * Return an array of arrays for punctuation values.
   *
   * Returns an array of arrays for punctuation values keyed by a name, including
   * the value and a textual description.
   * Can and should be expanded to include "all" non text punctuation values.
   *
   * @return array
   *   An array of arrays for punctuation values keyed by a name, including the
   *   value and a textual description.
   */
  public function getPunctuationCharacters() {
    if (empty($this->punctuationCharacters)) {
      $langcode = $this->languageManager->getCurrentLanguage()->getId();

      $cid = 'pathauto:punctuation:' . $langcode;
      if ($cache = $this->cacheBackend->get($cid)) {
        $this->punctuationCharacters = $cache->data;
      }
      else {
        $punctuation = array();
        $punctuation['double_quotes']      = array('value' => '"', 'name' => t('Double quotation marks'));
        $punctuation['quotes']             = array('value' => '\'', 'name' => t("Single quotation marks (apostrophe)"));
        $punctuation['backtick']           = array('value' => '`', 'name' => t('Back tick'));
        $punctuation['comma']              = array('value' => ',', 'name' => t('Comma'));
        $punctuation['period']             = array('value' => '.', 'name' => t('Period'));
        $punctuation['hyphen']             = array('value' => '-', 'name' => t('Hyphen'));
        $punctuation['underscore']         = array('value' => '_', 'name' => t('Underscore'));
        $punctuation['colon']              = array('value' => ':', 'name' => t('Colon'));
        $punctuation['semicolon']          = array('value' => ';', 'name' => t('Semicolon'));
        $punctuation['pipe']               = array('value' => '|', 'name' => t('Vertical bar (pipe)'));
        $punctuation['left_curly']         = array('value' => '{', 'name' => t('Left curly bracket'));
        $punctuation['left_square']        = array('value' => '[', 'name' => t('Left square bracket'));
        $punctuation['right_curly']        = array('value' => '}', 'name' => t('Right curly bracket'));
        $punctuation['right_square']       = array('value' => ']', 'name' => t('Right square bracket'));
        $punctuation['plus']               = array('value' => '+', 'name' => t('Plus sign'));
        $punctuation['equal']              = array('value' => '=', 'name' => t('Equal sign'));
        $punctuation['asterisk']           = array('value' => '*', 'name' => t('Asterisk'));
        $punctuation['ampersand']          = array('value' => '&', 'name' => t('Ampersand'));
        $punctuation['percent']            = array('value' => '%', 'name' => t('Percent sign'));
        $punctuation['caret']              = array('value' => '^', 'name' => t('Caret'));
        $punctuation['dollar']             = array('value' => '$', 'name' => t('Dollar sign'));
        $punctuation['hash']               = array('value' => '#', 'name' => t('Number sign (pound sign, hash)'));
        $punctuation['at']                 = array('value' => '@', 'name' => t('At sign'));
        $punctuation['exclamation']        = array('value' => '!', 'name' => t('Exclamation mark'));
        $punctuation['tilde']              = array('value' => '~', 'name' => t('Tilde'));
        $punctuation['left_parenthesis']   = array('value' => '(', 'name' => t('Left parenthesis'));
        $punctuation['right_parenthesis']  = array('value' => ')', 'name' => t('Right parenthesis'));
        $punctuation['question_mark']      = array('value' => '?', 'name' => t('Question mark'));
        $punctuation['less_than']          = array('value' => '<', 'name' => t('Less-than sign'));
        $punctuation['greater_than']       = array('value' => '>', 'name' => t('Greater-than sign'));
        $punctuation['slash']              = array('value' => '/', 'name' => t('Slash'));
        $punctuation['back_slash']         = array('value' => '\\', 'name' => t('Backslash'));

        // Allow modules to alter the punctuation list and cache the result.
        $this->moduleHandler->alter('pathauto_punctuation_chars', $punctuation);
        $this->cacheBackend->set($cid, $punctuation);
        $this->punctuationCharacters = $punctuation;
      }
    }

    return $this->punctuationCharacters;
  }


  /**
   * Apply patterns to create an alias.
   *
   * @param string $module
   *   The name of your module (e.g., 'node').
   * @param string $op
   *   Operation being performed on the content being aliased
   *   ('insert', 'update', 'return', or 'bulkupdate').
   * @param string $source
   *   An internal Drupal path to be aliased.
   * @param array $data
   *   An array of keyed objects to pass to token_replace(). For simple
   *   replacement scenarios 'node', 'user', and others are common keys, with an
   *   accompanying node or user object being the value. Some token types, like
   *   'site', do not require any explicit information from $data and can be
   *   replaced even if it is empty.
   * @param string $type
   *   For modules which provided pattern items in hook_pathauto(),
   *   the relevant identifier for the specific item to be aliased
   *   (e.g., $node->type).
   * @param string $language
   *   A string specify the path's language.
   *
   * @return array|string
   *   The alias that was created.
   *
   * @see _pathauto_set_alias()
   */
  public function createAlias($module, $op, $source, $data, $type = NULL, $language = LanguageInterface::LANGCODE_NOT_SPECIFIED) {
    $config = $this->configFactory->get('pathauto.settings');
    module_load_include('inc', 'pathauto');

    // Retrieve and apply the pattern for this content type.
    $pattern = $this->getPatternByEntity($module, $type, $language);

    // Allow other modules to alter the pattern.
    $context = array(
      'module' => $module,
      'op' => $op,
      'source' => $source,
      'data' => $data,
      'type' => $type,
      'language' => &$language,
    );
    $this->moduleHandler->alter('pathauto_pattern', $pattern, $context);

    if (empty($pattern)) {
      // No pattern? Do nothing (otherwise we may blow away existing aliases...)
      return NULL;
    }

    // Special handling when updating an item which is already aliased.
    $existing_alias = NULL;
    if ($op == 'update' || $op == 'bulkupdate') {
      if ($existing_alias = _pathauto_existing_alias_data($source, $language)) {
        switch ($config->get('update_action')) {
          case PATHAUTO_UPDATE_ACTION_NO_NEW:
            // If an alias already exists,
            // and the update action is set to do nothing,
            // then gosh-darn it, do nothing.
            return NULL;
        }
      }
    }

    // Replace any tokens in the pattern.
    // Uses callback option to clean replacements. No sanitization.
    $alias = $this->token->replace($pattern, $data, array(
      'sanitize' => FALSE,
      'clear' => TRUE,
      'callback' => 'pathauto_clean_token_values',
      'language' => (object) array('language' => $language),
      'pathauto' => TRUE,
    ));

    // Check if the token replacement has not actually replaced any values. If
    // that is the case, then stop because we should not generate an alias.
    // @see token_scan()
    $pattern_tokens_removed = preg_replace('/\[[^\s\]:]*:[^\s\]]*\]/', '', $pattern);
    if ($alias === $pattern_tokens_removed) {
      return NULL;
    }

    $alias = $this->aliasCleaner->cleanAlias($alias);

    // Allow other modules to alter the alias.
    $context['source'] = &$source;
    $context['pattern'] = $pattern;
    $this->moduleHandler->alter('pathauto_alias', $alias, $context);

    // If we have arrived at an empty string, discontinue.
    if (!Unicode::strlen($alias)) {
      return NULL;
    }

    // If the alias already exists, generate a new, hopefully unique, variant.
    $original_alias = $alias;
    $this->aliasUniquifier->uniquify($alias, $source, $language);
    if ($original_alias != $alias) {
      // Alert the user why this happened.
      _pathauto_verbose(t('The automatically generated alias %original_alias conflicted with an existing alias. Alias changed to %alias.', array(
        '%original_alias' => $original_alias,
        '%alias' => $alias,
      )), $op);
    }

    // Return the generated alias if requested.
    if ($op == 'return') {
      return $alias;
    }

    // Build the new path alias array and send it off to be created.
    $path = array(
      'source' => $source,
      'alias' => $alias,
      'language' => $language,
    );

    return _pathauto_set_alias($path, $existing_alias, $op);
  }

  /**
   * Load an URL alias pattern by entity, bundle, and language.
   *
   * @param $entity_type_id
   *   An entity (e.g. node, taxonomy, user, etc.)
   * @param $bundle
   *   A bundle (e.g. content type, vocabulary ID, etc.)
   * @param $language
   *   A language code, defaults to the LANGUAGE_NONE constant.
   */
  public function getPatternByEntity($entity_type_id, $bundle = '', $language = LanguageInterface::LANGCODE_NOT_SPECIFIED) {
    $config = $this->configFactory->get('pathauto.pattern');

    $pattern_id = "$entity_type_id:$bundle:$language";
    if (!isset($this->patterns[$pattern_id])) {
      $pattern = '';
      $variables = array();
      if ($language != LanguageInterface::LANGCODE_NOT_SPECIFIED) {
        $variables[] = "{$entity_type_id}.{$bundle}.{$language}";
      }
      if ($bundle) {
        $variables[] = "{$entity_type_id}.{$bundle}._default";
      }
      $variables[] = "{$entity_type_id}._default";

      foreach ($variables as $variable) {
        if ($pattern = trim($config->get($variable))) {
          break;
        }
      }

      $this->patterns[$pattern_id] = $pattern;
    }

    return $this->patterns[$pattern_id];
  }

  /**
   * Resets internal caches.
   */
  public function resetCaches() {
    $this->patterns = array();
    $this->cleanStringCache = array();
  }

}
