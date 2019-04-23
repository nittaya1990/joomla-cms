<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_finder
 *
 * @copyright   Copyright (C) 2005 - 2019 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Finder\Site\View\Search;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Path;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Profiler\Profiler;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Finder\Site\Helper\FinderHelper;

/**
 * Search HTML view class for the Finder package.
 *
 * @since  2.5
 */
class HtmlView extends BaseHtmlView
{
	/**
	 * The query indexer object
	 *
	 * @var    \FinderIndexerQuery
	 * @since  4.0.0
	 */
	protected $query;

	/**
	 * The page parameters
	 *
	 * @var  \Joomla\Registry\Registry|null
	 */
	protected $params = null;

	/**
	 * The model state
	 *
	 * @var  \JObject
	 */
	protected $state;

	/**
	 * The logged in user
	 *
	 * @var  \JUser|null
	 */
	protected $user = null;

	/**
	 * The suggested search query
	 *
	 * @var   string|false
	 * @since 4.0.0
	 */
	protected $suggested = false;

	/**
	 * The explained (human-readable) search query
	 *
	 * @var   string|null
	 * @since 4.0.0
	 */
	protected $explained = null;

	/**
	 * The page class suffix
	 *
	 * @var    string
	 * @since  4.0.0
	 */
	protected $pageclass_sfx = '';

	/**
	 * An array of results
	 *
	 * @var    array
	 *
	 * @since  3.8.0
	 */
	protected $results;

	/**
	 * The total number of items
	 *
	 * @var    integer
	 *
	 * @since  3.8.0
	 */
	protected $total;

	/**
	 * The pagination object
	 *
	 * @var    Pagination
	 *
	 * @since  3.8.0
	 */
	protected $pagination;

	/**
	 * Method to display the view.
	 *
	 * @param   string  $tpl  A template file to load. [optional]
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function display($tpl = null)
	{
		$app = Factory::getApplication();
		$this->params = $app->getParams();

		// Get view data.
		$this->state = $this->get('State');
		$this->query = $this->get('Query');
		\JDEBUG ? Profiler::getInstance('Application')->mark('afterFinderQuery') : null;
		$this->results = $this->get('Items');
		\JDEBUG ? Profiler::getInstance('Application')->mark('afterFinderResults') : null;
		$this->total = $this->get('Total');
		\JDEBUG ? Profiler::getInstance('Application')->mark('afterFinderTotal') : null;
		$this->pagination = $this->get('Pagination');
		\JDEBUG ? Profiler::getInstance('Application')->mark('afterFinderPagination') : null;

		// Flag indicates to not add limitstart=0 to URL
		$pagination->hideEmptyLimitstart = true;

		// Check for errors.
		if (count($errors = $this->get('Errors')))
		{
			throw new \JViewGenericdataexception(implode("\n", $errors), 500);
		}

		// Configure the pathway.
		if (!empty($this->query->input))
		{
			$app->getPathway()->addItem($this->escape($this->query->input));
		}

		// Check for a double quote in the query string.
		if (strpos($this->query->input, '"'))
		{
			// Get the application router.
			$router = $app->getRouter();

			// Fix the q variable in the URL.
			if ($router->getVar('q') !== $this->query->input)
			{
				$router->setVar('q', $this->query->input);
			}
		}

		// Run an event on each result item
		if (is_array($this->results))
		{
			// Import Finder plugins
			PluginHelper::importPlugin('finder');

			foreach ($this->results as $result)
			{
				$app->triggerEvent('onFinderResult', array(&$result, &$this->query));
			}
		}

		// Log the search
		FinderHelper::logSearch($this->query, $this->total);

		// Push out the query data.
		\JHtml::addIncludePath(JPATH_COMPONENT . '/helpers/html');
		$this->suggested = \JHtml::_('query.suggested', $this->query);
		$this->explained = \JHtml::_('query.explained', $this->query);

		// Escape strings for HTML output
		$this->pageclass_sfx = htmlspecialchars($this->params->get('pageclass_sfx'));

		// Check for layout override only if this is not the active menu item
		// If it is the active menu item, then the view and category id will match
		$active = $app->getMenu()->getActive();

		if (isset($active->query['layout']))
		{
			// We need to set the layout in case this is an alternative menu item (with an alternative layout)
			$this->setLayout($active->query['layout']);
		}

		$this->prepareDocument($this->query);

		\JDEBUG ? Profiler::getInstance('Application')->mark('beforeFinderLayout') : null;

		parent::display($tpl);

		\JDEBUG ? Profiler::getInstance('Application')->mark('afterFinderLayout') : null;
	}

	/**
	 * Method to get hidden input fields for a get form so that control variables
	 * are not lost upon form submission
	 *
	 * @return  string  A string of hidden input form fields
	 *
	 * @since   2.5
	 */
	protected function getFields()
	{
		$fields = null;

		// Get the URI.
		$uri = \JUri::getInstance(\JRoute::_($this->query->toUri()));
		$uri->delVar('q');
		$uri->delVar('o');
		$uri->delVar('t');
		$uri->delVar('d1');
		$uri->delVar('d2');
		$uri->delVar('w1');
		$uri->delVar('w2');
		$elements = $uri->getQuery(true);

		// Create hidden input elements for each part of the URI.
		foreach ($elements as $n => $v)
		{
			if (is_scalar($v))
			{
				$fields .= '<input type="hidden" name="' . $n . '" value="' . $v . '">';
			}
		}

		return $fields;
	}

	/**
	 * Method to get the layout file for a search result object.
	 *
	 * @param   string  $layout  The layout file to check. [optional]
	 *
	 * @return  string  The layout file to use.
	 *
	 * @since   2.5
	 */
	protected function getLayoutFile($layout = null)
	{
		// Create and sanitize the file name.
		$file = $this->_layout . '_' . preg_replace('/[^A-Z0-9_\.-]/i', '', $layout);

		// Check if the file exists.
		$filetofind = $this->_createFileName('template', array('name' => $file));
		$exists     = Path::find($this->_path['template'], $filetofind);

		return ($exists ? $layout : 'result');
	}

	/**
	 * Prepares the document
	 *
	 * @param   \FinderIndexerQuery  $query  The search query
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	protected function prepareDocument($query)
	{
		$app   = Factory::getApplication();
		$menus = $app->getMenu();

		// Because the application sets a default page title,
		// we need to get it from the menu item itself
		$menu = $menus->getActive();

		if ($menu)
		{
			$this->params->def('page_heading', $this->params->get('page_title', $menu->title));
		}
		else
		{
			$this->params->def('page_heading', \JText::_('COM_FINDER_DEFAULT_PAGE_TITLE'));
		}

		$title = $this->params->get('page_title', '');

		if (empty($title))
		{
			$title = $app->get('sitename');
		}
		elseif ($app->get('sitename_pagetitles', 0) == 1)
		{
			$title = \JText::sprintf('JPAGETITLE', $app->get('sitename'), $title);
		}
		elseif ($app->get('sitename_pagetitles', 0) == 2)
		{
			$title = \JText::sprintf('JPAGETITLE', $title, $app->get('sitename'));
		}

		$this->document->setTitle($title);

		if ($layout = $this->params->get('article_layout'))
		{
			$this->setLayout($layout);
		}

		// Configure the document meta-description.
		if (!empty($this->explained))
		{
			$explained = $this->escape(html_entity_decode(strip_tags($this->explained), ENT_QUOTES, 'UTF-8'));
			$this->document->setDescription($explained);
		}
		elseif ($this->params->get('menu-meta_description'))
		{
			$this->document->setDescription($this->params->get('menu-meta_description'));
		}

		// Configure the document meta-keywords.
		if (!empty($query->highlight))
		{
			$this->document->setMetaData('keywords', implode(', ', $query->highlight));
		}
		elseif ($this->params->get('menu-meta_keywords'))
		{
			$this->document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
		}

		if ($this->params->get('robots'))
		{
			$this->document->setMetadata('robots', $this->params->get('robots'));
		}

		// Check for OpenSearch
		if ($this->params->get('opensearch', 1))
		{
			$ostitle = $this->params->get('opensearch_name', Text::_('COM_FINDER_OPENSEARCH_NAME') . ' ' . Factory::getApplication()->get('sitename'));
			Factory::getDocument()->addHeadLink(
				Uri::getInstance()->toString(array('scheme', 'host', 'port')) . Route::_('index.php?option=com_finder&view=search&format=opensearch'),
				'search', 'rel', array('title' => $ostitle, 'type' => 'application/opensearchdescription+xml')
			);
		}

		// Add feed link to the document head.
		if ($this->params->get('show_feed_link', 1) == 1)
		{
			// Add the RSS link.
			$props = array('type' => 'application/rss+xml', 'title' => 'RSS 2.0');
			$route = \JRoute::_($this->query->toUri() . '&format=feed&type=rss');
			$this->document->addHeadLink($route, 'alternate', 'rel', $props);

			// Add the ATOM link.
			$props = array('type' => 'application/atom+xml', 'title' => 'Atom 1.0');
			$route = \JRoute::_($this->query->toUri() . '&format=feed&type=atom');
			$this->document->addHeadLink($route, 'alternate', 'rel', $props);
		}
	}
}