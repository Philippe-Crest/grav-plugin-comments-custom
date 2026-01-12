<?php
namespace Grav\Plugin;

use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\User\User;
use Grav\Common\Utils;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

class CommentsPlugin extends Plugin
{
    protected $route = 'comments';
    protected $enable = false;
    protected $comments_cache_id;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
        ];
    }

    /**
     * Register plugin permissions for Admin.
     *
     * @param PermissionsRegisterEvent $event
     * @return void
     */
    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml('plugin://comments/permissions.yaml');
        $event->permissions->addActions($actions);
    }

    /**
     * Initialize form if the page has one. Also catches form processing if user posts the form.
     *
     * Used by Form plugin < 2.0, kept for backwards compatibility
     *
     * @deprecated
     */
    public function onPageInitialized()
    {
        /** @var Page $page */
        $page = $this->grav['page'];
        if (!$page) {
            return;
        }

        if ($this->enable) {
            $header = $page->header();
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.comments.form');
                $page->header($header);
            }
        }
    }

    /**
     * Add the comment form information to the page header dynamically
     *
     * Used by Form plugin >= 2.0
     */
    public function onFormPageHeaderProcessed(Event $event)
    {
        $header = $event['header'];

        if ($this->enable) {
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.comments.form');
            }
        }

        $event->header = $header;
    }

    public function onTwigSiteVariables() {
        // Old way
        $enabled = $this->enable;
        $comments = $this->fetchComments();

        $this->grav['twig']->enable_comments_plugin = $enabled;
        $this->grav['twig']->comments = $comments;

        // New way
        $this->grav['twig']->twig_vars['enable_comments_plugin'] = $enabled;
        $this->grav['twig']->twig_vars['comments'] = $comments;

    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable() {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.comments.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.comments.enable_on_routes');

        $path = $uri->path();

        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Frontend side initialization
     */
    public function initializeFrontend()
    {
        $this->calculateEnable();

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
        ]);

        if ($this->enable) {
            $this->enable([
                'onFormProcessed' => ['onFormProcessed', 0],
                'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 0],
                'onPageInitialized' => ['onPageInitialized', 10],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }

        $cache = $this->grav['cache'];
        $uri = $this->grav['uri'];

        //init cache id
        $this->comments_cache_id = md5('comments-data' . $cache->getKey() . '-' . $uri->url());
    }

    /**
     * Admin side initialization
     */
    public function initializeAdmin()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
            'onTask.trashComment' => ['onTaskTrashComment', 0],
            'onTask.restoreTrashComment' => ['onTaskRestoreTrashComment', 0],
        ]);

        if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        $page = (int) $uri->param('page');
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        if ($isAjax && $page > 0) {
            if (!$this->isAuthorized('admin.comments')) {
                $this->sendJson(['error' => $this->getNotAuthorizedMessage()], 403);
            }

            $mode = $this->getAdminMode();
            $comments = $mode === 'trash' ? $this->getLastTrashedComments($page) : $this->getLastComments($page);
            $this->sendJson($comments);
        }

        $mode = $this->getAdminMode();
        $comments = $mode === 'trash' ? $this->getLastTrashedComments($page) : $this->getLastComments($page);

        $this->grav['twig']->comments = $comments;
        $this->grav['twig']->pages = $this->fetchPages($mode === 'trash');
        $this->grav['twig']->comments_mode = $mode;
    }

    /**
     * Handle trash comment task (POST).
     */
    public function onTaskTrashComment(Event $event)
    {
        if (!$this->isPluginActiveAdmin($this->route)) {
            return;
        }

        if (!$this->isAuthorized('admin.comments')) {
            $this->grav['admin']->setMessage($this->getNotAuthorizedMessage(), 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            $event->stopPropagation();
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $url = $this->grav['uri']->url();
        $postTask = (string) $this->grav['uri']->post('task');
        $eventTask = isset($event['task']) ? (string) $event['task'] : '';
        $postCid = (string) $this->grav['uri']->post('cid');
        $postRelPath = (string) $this->grav['uri']->post('relPath');
        $postNonce = (string) $this->grav['uri']->post('admin-nonce');

        if ($this->grav['config']->get('plugins.comments.debug_tasks')) {
            $this->grav['log']->info(
                'Comments task debug: url=' . $url
                . ' method=' . $method
                . ' post_task=' . $postTask
                . ' event_task=' . $eventTask
                . ' post_cid=' . $postCid
                . ' post_relPath=' . $postRelPath
            );
            $this->grav['admin']->setMessage(
                'DEBUG: onTask.trashComment reached, task=' . ($postTask ?: $eventTask),
                'info'
            );
        }

        $cid = $postCid;
        $relPath = $postRelPath;

        if ($cid === '' || $relPath === '') {
            $this->grav['admin']->setMessage('Missing comment identifier.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }

        if (strpos($relPath, '..') !== false || Utils::startsWith($relPath, '/') || strpos($relPath, '\\') !== false) {
            $this->grav['admin']->setMessage('Invalid comment path.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }
        if (!preg_match('~^[A-Za-z0-9/_\\-.]+\\.yaml$~', $relPath)) {
            $this->grav['admin']->setMessage('Invalid comment path.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }

        if (!$postNonce || !Utils::verifyNonce($postNonce, 'admin-form')) {
            $this->grav['admin']->setMessage('Invalid security token.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }

        $result = $this->trashCommentByCid($relPath, $cid);
        $this->grav['admin']->setMessage(
            $result['message'],
            $result['success'] ? 'success' : 'error'
        );
        $this->grav['admin']->redirect($this->getAdminRedirectRoute());
        $event->stopPropagation();
    }

    /**
     * Handle restore comment task (POST).
     */
    public function onTaskRestoreTrashComment(Event $event)
    {
        if (!$this->isPluginActiveAdmin($this->route)) {
            return;
        }

        if (!$this->isAuthorized('admin.comments')) {
            $this->grav['admin']->setMessage($this->getNotAuthorizedMessage(), 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            $event->stopPropagation();
            return;
        }

        $postCid = (string) $this->grav['uri']->post('cid');
        $postRelPath = (string) $this->grav['uri']->post('relPath');
        $postNonce = (string) $this->grav['uri']->post('admin-nonce');

        if ($postCid === '' || $postRelPath === '') {
            $this->grav['admin']->setMessage('Missing comment identifier.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }

        if (strpos($postRelPath, '..') !== false || Utils::startsWith($postRelPath, '/') || strpos($postRelPath, '\\') !== false) {
            $this->grav['admin']->setMessage('Invalid comment path.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }
        if (!preg_match('~^[A-Za-z0-9/_\\-.]+\\.yaml$~', $postRelPath)) {
            $this->grav['admin']->setMessage('Invalid comment path.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }

        if (!$postNonce || !Utils::verifyNonce($postNonce, 'admin-form')) {
            $this->grav['admin']->setMessage('Invalid security token.', 'error');
            $this->grav['admin']->redirect($this->getAdminRedirectRoute());
            return;
        }

        $result = $this->restoreTrashCommentByCid($postRelPath, $postCid);
        $this->grav['admin']->setMessage(
            $result['message'],
            $result['success'] ? 'success' : 'error'
        );
        $this->grav['admin']->redirect($this->getAdminRedirectRoute());
        $event->stopPropagation();
    }

    private function getAdminMode(): string
    {
        $uri = $this->grav['uri'];
        $trashParam = (string) $uri->param('trash');
        $trashQuery = (string) $uri->query('trash');

        return ($trashParam === '1' || $trashQuery === '1') ? 'trash' : 'comments';
    }

    private function getAdminRedirectRoute(): string
    {
        return $this->route . ($this->getAdminMode() === 'trash' ? '/trash:1' : '');
    }

    private function isAuthorized(string $permission): bool
    {
        $admin = $this->grav['admin'] ?? null;
        $user = $admin ? $admin->user : ($this->grav['user'] ?? null);

        return $user ? (bool) $user->authorize($permission) : false;
    }

    private function getNotAuthorizedMessage(): string
    {
        return $this->grav['language']->translate('PLUGINS.COMMENTS.NOT_AUTHORIZED');
    }

    /**
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->initializeAdmin();
        } else {
            $this->initializeFrontend();
        }
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        if (!$this->active) {
            return;
        }

        switch ($action) {
            case 'addComment':
                $post = isset($_POST['data']) ? $_POST['data'] : [];

                $path = $this->grav['uri']->path();

                $lang = filter_var(urldecode($post['lang']), FILTER_SANITIZE_STRING);
                $text = filter_var(urldecode($post['text']), FILTER_SANITIZE_STRING);
                $name = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
                $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
                $title = filter_var(urldecode($post['title']), FILTER_SANITIZE_STRING);

                if (isset($this->grav['user'])) {
                    $user = $this->grav['user'];
                    if ($user->authenticated) {
                        $name = $user->fullname;
                        $email = $user->email;
                    }
                }

                /** @var Language $language */
                $language = $this->grav['language'];
                $lang = $language->getLanguage();

                $filename = DATA_DIR . 'comments';
                $filename .= ($lang ? '/' . $lang : '');
                $filename .= $path . '.yaml';
                $file = File::instance($filename);

                if (file_exists($filename)) {
                    $data = Yaml::parse($file->content());

                    $data['comments'][] = [
                        'text' => $text,
                        'date' => date('D, d M Y H:i:s', time()),
                        'author' => $name,
                        'email' => $email
                    ];
                } else {
                    $data = array(
                        'title' => $title,
                        'lang' => $lang,
                        'comments' => array([
                            'text' => $text,
                            'date' => date('D, d M Y H:i:s', time()),
                            'author' => $name,
                            'email' => $email
                        ])
                    );
                }

                $file->save(Yaml::dump($data));

                //clear cache
                $this->grav['cache']->delete($this->comments_cache_id);

                break;
        }
    }

    private function getFilesOrderedByModifiedDate($path = '') {
        $files = [];

        if (!$path) {
            $path = DATA_DIR . 'comments';
        }

        if (!file_exists($path)) {
            Folder::mkdir($path);
        }

        $dirItr     = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filterItr  = new RecursiveFolderFilterIterator($dirItr);
        $itr        = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);

        $itrItr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
        $filesItr = new \RegexIterator($itrItr, '/^.+\.yaml$/i');

        // Collect files if modified in the last 7 days
        foreach ($filesItr as $filepath => $file) {
            $modifiedDate = $file->getMTime();
            $sevenDaysAgo = time() - (7 * 24 * 60 * 60);

            if ($modifiedDate < $sevenDaysAgo) {
                continue;
            }

            $files[] = (object)array(
                "modifiedDate" => $modifiedDate,
                "fileName" => $file->getFilename(),
                "filePath" => $filepath,
                "data" => Yaml::parse(file_get_contents($filepath))
            );
        }

        // Traverse folders and recurse
        foreach ($itr as $file) {
            if ($file->isDir()) {
                $this->getFilesOrderedByModifiedDate($file->getPath() . '/' . $file->getFilename());
            }
        }

        // Order files by last modified date
        usort($files, function($a, $b) {
            return !($a->modifiedDate > $b->modifiedDate);
        });

        return $files;
    }

    private function getLastComments($page = 0) {
        $number = 30;

        $files = [];
        $files = $this->getFilesOrderedByModifiedDate();
        $comments = [];

        foreach($files as $file) {
            $relPath = $this->getRelPathFromActivePath($file->filePath);
            $data = $this->getDataFromFilename('/' . $relPath);
            if (!is_array($data) || empty($data['comments'])) {
                continue;
            }

            for ($i = 0; $i < count($data['comments']); $i++) {
                $commentTimestamp = 0;
                if (!empty($data['comments'][$i]['date'])) {
                    $dt = \DateTime::createFromFormat('D, d M Y H:i:s', $data['comments'][$i]['date']);
                    if ($dt) {
                        $commentTimestamp = $dt->getTimestamp();
                    }
                }
                $relPath = $this->getRelPathFromActivePath($file->filePath);
                if ($relPath === '') {
                    continue;
                }

                $data['comments'][$i]['pageTitle'] = $data['title'];
                $data['comments'][$i]['relPath'] = $relPath;
                $data['comments'][$i]['timestamp'] = $commentTimestamp;
                $data['comments'][$i]['cid'] = $this->computeCid($relPath, $data['comments'][$i]);
            }
            if (count($data['comments'])) {
                $comments = array_merge($comments, $data['comments']);
            }
        }

        // Order comments by date
        usort($comments, function($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        $totalAvailable = count($comments);
        $comments = array_slice($comments, $page * $number, $number);
        $totalRetrieved = count($comments);

        return (object)array(
            "comments" => $comments,
            "page" => $page,
            "totalAvailable" => $totalAvailable,
            "totalRetrieved" => $totalRetrieved
        );
    }

    private function getLastTrashedComments($page = 0) {
        $number = 30;

        $files = [];
        $files = $this->getFilesOrderedByModifiedDate(DATA_DIR . 'comments-trash');
        $comments = [];

        foreach($files as $file) {
            $relPath = $this->getRelPathFromTrashPath($file->filePath);
            $data = $this->getTrashDataFromFilename($relPath);
            if (!is_array($data) || empty($data['comments'])) {
                continue;
            }

            for ($i = 0; $i < count($data['comments']); $i++) {
                $commentTimestamp = 0;
                if (!empty($data['comments'][$i]['date'])) {
                    $dt = \DateTime::createFromFormat('D, d M Y H:i:s', $data['comments'][$i]['date']);
                    if ($dt) {
                        $commentTimestamp = $dt->getTimestamp();
                    }
                }
                $relPath = $this->getRelPathFromTrashPath($file->filePath);
                if ($relPath === '') {
                    continue;
                }

                $data['comments'][$i]['pageTitle'] = $data['title'];
                $data['comments'][$i]['relPath'] = $relPath;
                $data['comments'][$i]['timestamp'] = $commentTimestamp;
                $data['comments'][$i]['cid'] = $this->computeCid($relPath, $data['comments'][$i]);
            }
            if (count($data['comments'])) {
                $comments = array_merge($comments, $data['comments']);
            }
        }

        // Order comments by date
        usort($comments, function($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        $totalAvailable = count($comments);
        $comments = array_slice($comments, $page * $number, $number);
        $totalRetrieved = count($comments);

        return (object)array(
            "comments" => $comments,
            "page" => $page,
            "totalAvailable" => $totalAvailable,
            "totalRetrieved" => $totalRetrieved
        );
    }

    /**
     * Return the comments associated to the current route
     */
    private function fetchComments() {
        $cache = $this->grav['cache'];
        //search in cache
        if ($comments = $cache->fetch($this->comments_cache_id)) {
            return $comments;
        }

        $lang = $this->grav['language']->getLanguage();
        $filename = $lang ? '/' . $lang : '';
        $filename .= $this->grav['uri']->path() . '.yaml';

        $data = $this->getDataFromFilename($filename);
        $comments = isset($data['comments']) ? $data['comments'] : null;
        //save to cache if enabled
        $cache->save($this->comments_cache_id, $comments);
        return $comments;
    }

    /**
     * Return the latest commented pages
     */
    private function fetchPages(bool $useTrash = false) {
        $files = [];
        $files = $this->getFilesOrderedByModifiedDate($useTrash ? DATA_DIR . 'comments-trash' : '');

        $pages = [];

        foreach($files as $file) {
            $pages[] = [
                'title' => $file->data['title'],
                'commentsCount' => count($file->data['comments']),
                'lastCommentDate' => date('D, d M Y H:i:s', $file->modifiedDate)
            ];
        }

        return $pages;
    }

    /**
     * Move a comment from active storage to trash by cid.
     */
    public function trashCommentByCid(string $relPath, string $cid): array
    {
        $activePath = DATA_DIR . 'comments/' . ltrim($relPath, '/');
        $trashPath = DATA_DIR . 'comments-trash/' . ltrim($relPath, '/');

        if (!file_exists($activePath)) {
            return ['success' => false, 'message' => 'Active comments file not found.'];
        }

        $data = Yaml::parse(file_get_contents($activePath));
        if (!is_array($data) || !isset($data['comments']) || !is_array($data['comments'])) {
            return ['success' => false, 'message' => 'No comments found in active file.'];
        }

        $targetIndex = null;
        $targetComment = null;

        foreach ($data['comments'] as $index => $comment) {
            if ($this->computeCid($relPath, $comment) === $cid) {
                $targetIndex = $index;
                $targetComment = $comment;
                break;
            }
        }

        if ($targetIndex === null) {
            return ['success' => false, 'message' => 'Comment not found.'];
        }

        $trashDir = dirname($trashPath);
        if (!file_exists($trashDir)) {
            Folder::mkdir($trashDir);
        }

        $trashDataBefore = null;
        $trashFileExists = file_exists($trashPath);
        if ($trashFileExists) {
            $trashDataBefore = Yaml::parse(file_get_contents($trashPath));
            if (!is_array($trashDataBefore)) {
                $trashDataBefore = null;
            }
        }

        $trashData = $trashDataBefore ?? [
            'title' => $data['title'] ?? null,
            'lang' => $data['lang'] ?? null,
            'comments' => [],
        ];

        if (!is_array($trashData)) {
            $trashData = [
                'title' => $data['title'] ?? null,
                'lang' => $data['lang'] ?? null,
                'comments' => [],
            ];
        }
        if (!isset($trashData['comments']) || !is_array($trashData['comments'])) {
            $trashData['comments'] = [];
        }

        $trashData['comments'][] = $targetComment;

        $trashFile = File::instance($trashPath);
        try {
            $trashFile->save(Yaml::dump($trashData));
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to write trash file: ' . $e->getMessage()];
        }
        if (!file_exists($trashPath)) {
            return ['success' => false, 'message' => 'Failed to write trash file (file missing after save).'];
        }

        array_splice($data['comments'], $targetIndex, 1);
        $activeFile = File::instance($activePath);
        try {
            $activeFile->save(Yaml::dump($data));
        } catch (\Throwable $e) {
            $rollbackStatus = $this->rollbackTrashAfterActiveFailure($trashPath, $trashDataBefore, $trashFileExists);
            return [
                'success' => false,
                'message' => $this->formatRollbackFailureMessage($rollbackStatus),
            ];
        }
        if (!file_exists($activePath)) {
            $rollbackStatus = $this->rollbackTrashAfterActiveFailure($trashPath, $trashDataBefore, $trashFileExists);
            return [
                'success' => false,
                'message' => $this->formatRollbackFailureMessage($rollbackStatus),
            ];
        }

        return [
            'success' => true,
            'message' => $this->grav['language']->translate('PLUGINS.COMMENTS.COMMENT_TRASHED'),
        ];
    }

    /**
     * Restore a comment from trash to active by cid.
     */
    public function restoreTrashCommentByCid(string $relPath, string $cid): array
    {
        $activePath = DATA_DIR . 'comments/' . ltrim($relPath, '/');
        $trashPath = DATA_DIR . 'comments-trash/' . ltrim($relPath, '/');

        if (!file_exists($trashPath)) {
            return ['success' => false, 'message' => 'Trash comments file not found.'];
        }

        $trashData = Yaml::parse(file_get_contents($trashPath));
        if (!is_array($trashData) || !isset($trashData['comments']) || !is_array($trashData['comments'])) {
            return ['success' => false, 'message' => 'No comments found in trash file.'];
        }

        $targetIndex = null;
        $targetComment = null;

        foreach ($trashData['comments'] as $index => $comment) {
            if ($this->computeCid($relPath, $comment) === $cid) {
                $targetIndex = $index;
                $targetComment = $comment;
                break;
            }
        }

        if ($targetIndex === null) {
            return ['success' => false, 'message' => 'Comment not found in trash.'];
        }

        $activeDataBefore = null;
        $activeFileExists = file_exists($activePath);
        if ($activeFileExists) {
            $activeDataBefore = Yaml::parse(file_get_contents($activePath));
            if (!is_array($activeDataBefore)) {
                $activeDataBefore = null;
            }
        }

        $activeData = $activeDataBefore ?? [
            'title' => $trashData['title'] ?? null,
            'lang' => $trashData['lang'] ?? null,
            'comments' => [],
        ];

        if (!isset($activeData['comments']) || !is_array($activeData['comments'])) {
            $activeData['comments'] = [];
        }

        foreach ($activeData['comments'] as $existingComment) {
            if ($this->computeCid($relPath, $existingComment) === $cid) {
                array_splice($trashData['comments'], $targetIndex, 1);
                $trashFile = File::instance($trashPath);
                try {
                    $trashFile->save(Yaml::dump($trashData));
                } catch (\Throwable $e) {
                    return ['success' => false, 'message' => 'Failed to update trash file: ' . $e->getMessage()];
                }
                return ['success' => true, 'message' => 'Comment already restored.'];
            }
        }

        $activeData['comments'][] = $targetComment;
        $activeDir = dirname($activePath);
        if (!file_exists($activeDir)) {
            Folder::mkdir($activeDir);
        }

        $activeFile = File::instance($activePath);
        try {
            $activeFile->save(Yaml::dump($activeData));
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to write active file: ' . $e->getMessage()];
        }
        if (!file_exists($activePath)) {
            return ['success' => false, 'message' => 'Failed to write active file (file missing after save).'];
        }

        array_splice($trashData['comments'], $targetIndex, 1);
        $trashFile = File::instance($trashPath);
        try {
            $trashFile->save(Yaml::dump($trashData));
        } catch (\Throwable $e) {
            $rollbackStatus = $this->rollbackActiveAfterTrashFailure($activePath, $activeDataBefore, $activeFileExists);
            return [
                'success' => false,
                'message' => $this->formatRestoreRollbackFailureMessage($rollbackStatus),
            ];
        }
        if (!file_exists($trashPath)) {
            $rollbackStatus = $this->rollbackActiveAfterTrashFailure($activePath, $activeDataBefore, $activeFileExists);
            return [
                'success' => false,
                'message' => $this->formatRestoreRollbackFailureMessage($rollbackStatus),
            ];
        }

        return [
            'success' => true,
            'message' => $this->grav['language']->translate('PLUGINS.COMMENTS.COMMENT_RESTORED'),
        ];
    }

    /**
     * Best-effort rollback of trash after active file failure.
     */
    private function rollbackTrashAfterActiveFailure(string $trashPath, ?array $trashDataBefore, bool $trashFileExists): string
    {
        if ($trashDataBefore === null) {
            if ($trashFileExists) {
                return 'skipped';
            }
            if (file_exists($trashPath)) {
                return unlink($trashPath) ? 'ok' : 'failed';
            }
            return 'failed';
        }

        $bytes = file_put_contents($trashPath, Yaml::dump($trashDataBefore), LOCK_EX);
        return $bytes !== false ? 'ok' : 'failed';
    }

    /**
     * Best-effort rollback of active after trash file failure.
     */
    private function rollbackActiveAfterTrashFailure(string $activePath, ?array $activeDataBefore, bool $activeFileExists): string
    {
        if ($activeDataBefore === null) {
            if ($activeFileExists) {
                return 'skipped';
            }
            if (file_exists($activePath)) {
                return unlink($activePath) ? 'ok' : 'failed';
            }
            return 'failed';
        }

        $bytes = file_put_contents($activePath, Yaml::dump($activeDataBefore), LOCK_EX);
        return $bytes !== false ? 'ok' : 'failed';
    }

    /**
     * Format the rollback status message after trash file failure.
     */
    private function formatRestoreRollbackFailureMessage(string $rollbackStatus): string
    {
        if ($rollbackStatus === 'ok') {
            return 'Failed to update trash file; active rollback succeeded. Comment should not be duplicated.';
        }
        if ($rollbackStatus === 'skipped') {
            return 'Failed to update trash file; active rollback impossible. Comment may still exist in both locations.';
        }

        return 'Failed to update trash file; active rollback attempted (status: failed). Comment may still exist in both locations.';
    }

    /**
     * Format the rollback status message after active file failure.
     */
    private function formatRollbackFailureMessage(string $rollbackStatus): string
    {
        if ($rollbackStatus === 'ok') {
            return 'Failed to update active file; trash rollback succeeded. Comment should not be duplicated.';
        }
        if ($rollbackStatus === 'skipped') {
            return 'Failed to update active file; trash rollback impossible. Comment may still exist in both locations.';
        }

        return 'Failed to update active file; trash rollback attempted (status: failed). Comment may still exist in both locations.';
    }

    /**
     * Map an active comments file path to its relative path.
     */
    private function getRelPathFromActivePath(string $filePath): string
    {
        $activeRoot = DATA_DIR . 'comments/';
        if (Utils::startsWith($filePath, $activeRoot)) {
            return ltrim(substr($filePath, strlen($activeRoot)), '/');
        }

        return '';
    }

    /**
     * Map a trash comments file path to its relative path.
     */
    private function getRelPathFromTrashPath(string $filePath): string
    {
        $trashRoot = DATA_DIR . 'comments-trash/';
        if (Utils::startsWith($filePath, $trashRoot)) {
            return ltrim(substr($filePath, strlen($trashRoot)), '/');
        }

        return '';
    }


    /**
     * Given a data file route, return the YAML content already parsed
     */
    private function getDataFromFilename($fileRoute) {

        //Single item details
        $fileInstance = File::instance(DATA_DIR . 'comments/' . $fileRoute);

        if (!$fileInstance->content()) {
            //Item not found
            return;
        }

        $data = Yaml::parse($fileInstance->content());
        if (is_array($data) && isset($data['comments']) && is_array($data['comments'])) {
            foreach ($data['comments'] as $index => $comment) {
                if (!empty($comment['text']) && is_string($comment['text'])) {
                    $data['comments'][$index]['text'] = html_entity_decode(
                        $comment['text'],
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                }
            }
        }
        return $data;
    }

    private function computeCid(string $relPath, array $comment): string
    {
        $text = (string) ($comment['text'] ?? '');
        if ($text !== '') {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace(["\r\n", "\r"], "\n", $text);
        }

        $cidSource = $relPath
            . '|' . (string) ($comment['date'] ?? '')
            . '|' . (string) ($comment['author'] ?? '')
            . '|' . (string) ($comment['email'] ?? '')
            . '|' . $text;

        return substr(sha1($cidSource), 0, 12);
    }

    /**
     * Given a trash data file route, return the YAML content already parsed
     */
    private function getTrashDataFromFilename($fileRoute) {

        $fileRoute = ltrim((string) $fileRoute, '/');

        //Single item details
        $fileInstance = File::instance(DATA_DIR . 'comments-trash/' . $fileRoute);

        if (!$fileInstance->content()) {
            //Item not found
            return;
        }

        $data = Yaml::parse($fileInstance->content());
        if (is_array($data) && isset($data['comments']) && is_array($data['comments'])) {
            foreach ($data['comments'] as $index => $comment) {
                if (!empty($comment['text']) && is_string($comment['text'])) {
                    $data['comments'][$index]['text'] = html_entity_decode(
                        $comment['text'],
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                }
            }
        }
        return $data;
    }

    private function sendJson($payload, $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        exit;
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Add plugin templates path
     */
    public function onTwigAdminTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    /**
     * Add navigation item to the admin plugin
     */
    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_COMMENTS.COMMENTS'] = ['route' => $this->route, 'icon' => 'fa-file-text'];
    }

    /**
     * Exclude comments from the Data Manager plugin
     */
    public function onDataTypeExcludeFromDataManagerPluginHook()
    {
        $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'comments';
    }
}
