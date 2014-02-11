<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) Stefan Galinski <stefan@sgalinski.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

$pathToScriptmerger = t3lib_extMgm::extPath('scriptmerger');
require_once($pathToScriptmerger . 'Resources/Minify/ImportProcessor.php');
require_once($pathToScriptmerger . 'Resources/Minify/CSS.php');
require_once($pathToScriptmerger . 'Resources/Minify/CommentPreserver.php');
require_once($pathToScriptmerger . 'Resources/Minify/CSS/Compressor.php');
require_once($pathToScriptmerger . 'Resources/Minify/CSS/UriRewriter.php');

/**
 * This class contains the parsing and replacing functionality for css files
 */
class ScriptmergerCss extends ScriptmergerBase {
	/**
	 * holds the javascript code
	 *
	 * Structure:
	 * - $relation (rel attribute)
	 *   - $media (media attribute)
	 *     - $file
	 *       |-content => string
	 *       |-basename => string (base name of $file without file prefix)
	 *       |-minify-ignore => bool
	 *       |-merge-ignore => bool
	 *
	 * @var array
	 */
	protected $css = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
		Minify_ImportProcessor::$extensionConfiguration = $this->configuration;
	}

	/**
	 * Controller for the css parsing and replacement
	 *
	 * @return void
	 */
	public function process() {
		// fetch all remaining css contents
		$this->getFiles();

		// minify, compress and merging
		foreach ($this->css as $relation => $cssByRelation) {
			foreach ($cssByRelation as $media => $cssByMedia) {
				$mergedContent = '';
				$firstFreeIndex = -1;
				foreach ($cssByMedia as $index => $cssProperties) {
					$newFile = '';

					// file should be minified
					if ($this->configuration['css.']['minify.']['enable'] === '1' &&
						!$cssProperties['minify-ignore']
					) {
						$newFile = $this->minifyFile($cssProperties);
					}

					// file should be merged
					if ($this->configuration['css.']['merge.']['enable'] === '1' &&
						!$cssProperties['merge-ignore']
					) {
						if ($firstFreeIndex < 0) {
							$firstFreeIndex = $index;
						}

						// add content
						$mergedContent .= $cssProperties['content'] . LF;

						// remove file from array
						unset($this->css[$relation][$media][$index]);

						// we doesn't need to compress or add a new file to the array,
						// because the last one will finally not be needed anymore
						continue;
					}

					// file should be compressed instead?
					if ($this->configuration['css.']['compress.']['enable'] === '1' &&
						function_exists('gzcompress') && !$cssProperties['compress-ignore']
					) {
						$newFile = $this->compressFile($cssProperties);
					}

					// minification or compression was used
					if ($newFile !== '') {
						$this->css[$relation][$media][$index]['file'] = $newFile;
						$this->css[$relation][$media][$index]['content'] =
							$cssProperties['content'];
						$this->css[$relation][$media][$index]['basename'] =
							$cssProperties['basename'];
					}
				}

				// save merged content inside a new file
				if ($this->configuration['css.']['merge.']['enable'] === '1' && $mergedContent !== '') {
					if ($this->configuration['css.']['uniqueCharset.']['enable'] === '1') {
						$mergedContent = $this->uniqueCharset($mergedContent);
					}

					// create property array
					$properties = array(
						'content' => $mergedContent,
						'basename' => 'head-' . md5($mergedContent) . '.merged'
					);

					// write merged file in any case
					$newFile = $this->tempDirectories['merged'] . $properties['basename'] . '.css';
					if (!file_exists($newFile)) {
						$this->writeFile($newFile, $properties['content']);
					}

					// file should be compressed
					if ($this->configuration['css.']['compress.']['enable'] === '1' &&
						function_exists('gzcompress')
					) {
						$newFile = $this->compressFile($properties);
					}

					// add new entry
					$this->css[$relation][$media][$firstFreeIndex]['file'] = $newFile;
					$this->css[$relation][$media][$firstFreeIndex]['content'] =
						$properties['content'];
					$this->css[$relation][$media][$firstFreeIndex]['basename'] =
						$properties['basename'];
				}
			}
		}

		// write the conditional comments and possibly merged css files back to the document
		$this->writeToDocument();
	}

	/**
	 * Some browser fail on parsing merged CSS files if multiple charset definitions are found.
	 * Therefor we replace all charset definition's with an empty string and add a single charset
	 * definition to the beginning of the content. At least Webkit engines fail badly.
	 *
	 * @param string $content
	 * @return string
	 */
	protected function uniqueCharset($content) {
		if (!empty($this->configuration['css.']['uniqueCharset.']['value'])) {
			$content = preg_replace('/@charset[^;]+;/', '', $content);
			$content = $this->configuration['css.']['uniqueCharset.']['value'] . $content;
		}
		return $content;
	}

	/**
	 * This method parses the output content and saves any found css files or inline code
	 * into the "css" class property. The output content is cleaned up of the found results.
	 *
	 * @return void
	 */
	protected function getFiles() {
		// filter pattern for the inDoc styles (fetches the content)
		$filterInDocumentPattern = '/' .
			'<style.*?>' . // This expression removes the opening style tag
			'(?:.*?\/\*<!\[CDATA\[\*\/)?' . // and the optionally prefixed CDATA string.
			'\s*(.*?)' . // We save the pure css content,
			'(?:\s*\/\*\]\]>\*\/)?' . // remove the possible closing CDATA string
			'\s*<\/style>' . // and closing style tag
			'/is';

		// parse all available css code inside link and style tags
		$cssTags = array();
		$pattern = '/' .
			'<(link|sty)' . // Parse any link and style tags.
			'(?=.+?(?:media="(.*?)"|>))' . // Fetch the media attribute
			'(?=.+?(?:href="(.*?)"|>))' . // and the href attribute
			'(?=.+?(?:rel="(.*?)"|>))' . // and the rel attribute
			'(?=.+?(?:title="(.*?)"|>))' . // and the title attribute of the tag.
			'(?:[^>]+?\.css[^>]+?\/?>' . // Continue parsing from \1 to the closing tag.
			'|le[^>]*?>[^>]+?<\/style>)\s*' .
			'/is';

		preg_match_all($pattern, $GLOBALS['TSFE']->content, $cssTags);
		if (!count($cssTags[0])) {
			return;
		}

		// remove any css code inside the output content
		$GLOBALS['TSFE']->content = preg_replace($pattern, '', $GLOBALS['TSFE']->content, count($cssTags[0]));

		// parse matches
		$amountOfResults = count($cssTags[0]);
		for ($i = 0; $i < $amountOfResults; ++$i) {
			$content = '';

			// get media attribute (all as default if it's empty)
			$media = (trim($cssTags[2][$i]) === '') ? 'all' : $cssTags[2][$i];
			$media = implode(',', array_map('trim', explode(',', $media)));

			// get rel attribute (stylesheet as default if it's empty)
			$relation = (trim($cssTags[4][$i]) === '') ? 'stylesheet' : $cssTags[4][$i];

			// get source attribute
			$source = $cssTags[3][$i];

			// get title attribute
			$title = trim($cssTags[5][$i]);

			// add basic entry
			$this->css[$relation][$media][$i]['minify-ignore'] = FALSE;
			$this->css[$relation][$media][$i]['compress-ignore'] = FALSE;
			$this->css[$relation][$media][$i]['merge-ignore'] = FALSE;
			$this->css[$relation][$media][$i]['file'] = $source;
			$this->css[$relation][$media][$i]['content'] = '';
			$this->css[$relation][$media][$i]['basename'] = '';
			$this->css[$relation][$media][$i]['title'] = $title;

			// styles which are added inside the document must be parsed again
			// to fetch the pure css code
			$cssTags[1][$i] = ($cssTags[1][$i] === 'sty' ? 'style' : $cssTags[1][$i]);
			if ($cssTags[1][$i] === 'style') {
				$cssContent = array();
				preg_match_all($filterInDocumentPattern, $cssTags[0][$i], $cssContent);

				// we doesn't need to continue if it was an empty style tag
				if ($cssContent[1][0] === '') {
					unset($this->css[$relation][$media][$i]);
					continue;
				}

				// save the content into a temporary file
				$hash = md5($cssContent[1][0]);
				$source = $this->tempDirectories['temp'] . 'inDocument-' . $hash;
				$tempFile = $source . '.css';
				if (!file_exists($source . '.css')) {
					$this->writeFile($tempFile, $cssContent[1][0]);
				}

				// try to resolve any @import occurrences
				/** @noinspection PhpUndefinedClassInspection */
				$content = Minify_ImportProcessor::process($tempFile);
				$this->css[$relation][$media][$i]['file'] = $tempFile;
				$this->css[$relation][$media][$i]['content'] = $content;
				$this->css[$relation][$media][$i]['basename'] = basename($source);
			} elseif ($source !== '') {
				// try to fetch the content of the css file
				$file = $source;
				if ($GLOBALS['TSFE']->absRefPrefix !== '' && strpos($file, $GLOBALS['TSFE']->absRefPrefix) === 0) {
					$file = substr($file, strlen($GLOBALS['TSFE']->absRefPrefix) - 1);
				}
				if (file_exists(PATH_site . $file)) {
					$content = Minify_ImportProcessor::process(PATH_site . $file);
				} else {
					$tempFile = $this->getExternalFile($source);
					$content = Minify_ImportProcessor::process($tempFile);
				}

				// ignore this file if the content could not be fetched
				if ($content == '') {
					$this->css[$relation][$media][$i]['minify-ignore'] = TRUE;
					$this->css[$relation][$media][$i]['compress-ignore'] = TRUE;
					$this->css[$relation][$media][$i]['merge-ignore'] = TRUE;
					continue;
				}

				// check if the file should be ignored for some processes
				if ($this->configuration['css.']['minify.']['ignore'] !== '') {
					if (preg_match($this->configuration['css.']['minify.']['ignore'], $source)) {
						$this->css[$relation][$media][$i]['minify-ignore'] = TRUE;
					}
				}

				if ($this->configuration['css.']['compress.']['ignore'] !== '') {
					if (preg_match($this->configuration['css.']['compress.']['ignore'], $source)) {
						$this->css[$relation][$media][$i]['compress-ignore'] = TRUE;
					}
				}

				if ($this->configuration['css.']['merge.']['ignore'] !== '') {
					if (preg_match($this->configuration['css.']['merge.']['ignore'], $source)) {
						$this->css[$relation][$media][$i]['merge-ignore'] = TRUE;
					}
				}

				// set the css file with it's content
				$this->css[$relation][$media][$i]['content'] = $content;
			}

			// get base name for later usage
			// base name without file prefix and prefixed hash of the content
			$filename = basename($source);
			$hash = md5($content);
			$this->css[$relation][$media][$i]['basename'] =
				substr($filename, 0, strrpos($filename, '.')) . '-' . $hash;
		}
	}

	/**
	 * This method minifies a css file. It's based upon the Minify_CSS class
	 * of the project minify.
	 *
	 * @param array $properties properties of an entry (copy-by-reference is used!)
	 * @return string new filename
	 */
	protected function minifyFile(&$properties) {
		// get new filename
		$newFile = $this->tempDirectories['minified'] .
			$properties['basename'] . '.min.css';

		// stop further processing if the file already exists
		if (file_exists($newFile)) {
			$properties['basename'] .= '.min';
			$properties['content'] = file_get_contents($newFile);
			return $newFile;
		}

		// minify content
		/** @noinspection PhpUndefinedClassInspection */
		$properties['content'] = Minify_CSS::minify($properties['content']);

		// save content inside the new file
		$this->writeFile($newFile, $properties['content']);

		// save new part of the base name
		$properties['basename'] .= '.min';

		return $newFile;
	}

	/**
	 * This method compresses a css file.
	 *
	 * @param array $properties properties of an entry (copy-by-reference is used!)
	 * @return string new filename
	 */
	protected function compressFile(&$properties) {
		$newFile = $this->tempDirectories['compressed'] . $properties['basename'] . '.gz.css';
		if (file_exists($newFile)) {
			return $newFile;
		}

		$this->writeFile($newFile, gzencode($properties['content'], 5));

		return $newFile;
	}

	/**
	 * This method writes the css back to the document.
	 *
	 * @return void
	 */
	protected function writeToDocument() {
		// write all files back to the document
		foreach ($this->css as $relation => $cssByRelation) {
			$cssByRelation = array_reverse($cssByRelation);
			foreach ($cssByRelation as $media => $cssByMedia) {
				$cssByMedia = array_reverse($cssByMedia);
				foreach ($cssByMedia as $cssProperties) {
					$file = $cssProperties['file'];

					// normal file or http link?
					if (file_exists($file)) {
						$file = $GLOBALS['TSFE']->absRefPrefix .
							(PATH_site === '/' ? $file : str_replace(PATH_site, '', $file));
					}

					// build css script link or add the content directly into the document
					if ($this->configuration['css.']['addContentInDocument'] === '1') {
						$content = LF . "\t" .
							'<style media="' . $media . '" type="text/css">' . LF .
							"\t" . '/* <![CDATA[ */' . LF .
							"\t" . $cssProperties['content'] . LF .
							"\t" . '/* ]]> */' . LF .
							"\t" . '</style>' . LF;
					} else {
						$title = (trim($cssProperties['title']) !== '' ?
							'title="' . $cssProperties['title'] . '"' : '');
						$content = LF . "\t" . '<link rel="' . $relation . '" type="text/css" ' .
							'media="' . $media . '" ' . $title . ' href="' . $file . '" />' . LF;
					}

					// add content right after the opening head tag
					$GLOBALS['TSFE']->content = preg_replace(
						'/<(?:\/base|base|meta name="generator"|link|\/title|\/head).*?>/is',
						'\0' . $content,
						$GLOBALS['TSFE']->content,
						1
					);
				}
			}
		}
	}
}

?>