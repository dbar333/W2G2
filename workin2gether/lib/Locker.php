<?php

namespace OCA\w2g2;

class Locker {
    protected $safe = null;
    protected $naming = "";
    protected $directoryLock = "";
    protected $extended = "";
    protected $l;

    protected $request = null;
    protected $database = null;

    public function __construct()
    {
        Database::fetch($this->naming, 'suffix', "rule_username");
        Database::fetch($this->directoryLock, 'directory_locking', "directory_locking_all");
        Database::fetch($this->extended, 'extended', "0");

        $this->l = \OCP\Util::getL10N('w2g2');
    }

    public function handle()
    {
        if (isset($_POST['safe'])) {
            $this->safe = $_POST['safe'];
        }

        if ( ! isset($_POST['batch'])) {
            return $this->handleSingleFile();
        }

        return $this->handleMultipleFiles();
    }

    protected function handleSingleFile()
    {
        $fileData = [];

        $fileData['path'] = stripslashes($_POST['path']);
        $fileData['path'] = Helpers::decodeCharacters($fileData['path']);

        if (isset($_POST['owner'])) {
            $fileData['owner'] = $_POST['owner'];
        }

        if (isset($_POST['id'])) {
            $fileData['id'] = $_POST['id'];
        }

        if (isset($_POST['mountType'])) {
            $fileData['mountType'] = $_POST['mountType'];
        }

        if (isset($_POST['fileType'])) {
            $fileData['fileType'] = $_POST['fileType'];
        }

        return $this->check($fileData);
    }

    protected function handleMultipleFiles()
    {
        $files = json_decode($_POST['path'], true);
        $folder = $_POST['folder'];

        for ($i = 0; $i < count($files); $i++) {
            $fileData = [];

            $fileName = $files[$i][1];

            $fileData['id'] = $files[$i][0];
            $fileData['owner'] = $files[$i][2];
            $fileData['path'] = $folder . $fileName;
            $fileData['mountType'] = $files[$i][4];
            $fileData['fileType'] = count($files[$i]) >= 5 ? $files[$i][5] : null;

            $response = $this->check($fileData);

            if ($response !== null) {
                $files[$i][3] = $response;
            }
        }

        return json_encode($files);
    }

    protected function check($fileData)
    {
        if ($this->fileFromGroupFolder($fileData['mountType']) || $this->fileFromSharing($fileData['owner'])) {
            $lockfile = $this->getLockpathFromExternalSource($fileData['id']);
        } else {
            $lockfile = $this->getLockpathFromCurrentUserFiles($fileData['path']);
        }

        $db_lock_state = $this->getLockStateForFile($lockfile, $fileData['fileType']);

        if ($db_lock_state != null) {
            $lockedby_name = $this->getUserThatLockedTheFile($db_lock_state);
            $this->ShowUserName($lockedby_name);

            if ($this->safe === "false") {
                if ($this->currentUserIsTheOriginalLocker($lockedby_name)) {
                    Database::unlockFile($lockfile);

                    return " Unlocked.";
                }

                return " " . $this->l->t("No permission");
            } else {
                return $this->showLockedByMessage($lockedby_name, $this->l);
            }
        } else {
            if ($this->safe === "false") {
                $lockedby_name = $this->getCurrentUserName($this->naming);

                Database::lockFile($lockfile, $lockedby_name);

                $this->ShowDisplayName($lockedby_name); //Korrektur bei DisplayName

                return $this->showLockedByMessage($lockedby_name, $this->l);
            }
        }

        return null;
    }

    protected function extended_precheck($extended, $owner)
    {
        if ($extended == "0")
            return 0;
        elseif ($extended == "1") {
            if ($owner == \OCP\User::getUser())
                return 0;
        }

        return 1;
    }

    protected function ShowDisplayName(&$lockedby_name)
    {
        if (strstr($lockedby_name, "|")) {
            $temp_ln = explode("|", $lockedby_name);
            $lockedby_name = $temp_ln[0];
        }
    }

    protected function ShowUserName(&$lockedby_name)
    {
        if (strstr($lockedby_name, "|")) {
            $temp_ln = explode("|", $lockedby_name);
            $lockedby_name = $temp_ln[1];
        }
    }

    protected function fileFromGroupFolder($mountType)
    {
        return $mountType === 'group';
    }

    protected function fileFromSharing($owner)
    {
        return ! is_null($owner) && $owner !== '';
    }

    protected function getLockpathFromExternalSource($id)
    {
        $query = \OCP\DB::prepare("
          SELECT X.path, Y.id 
          FROM *PREFIX*filecache X 
          INNER JOIN *PREFIX*storages Y 
          ON X.storage = Y.numeric_id 
          WHERE X.fileid = ? LIMIT 1
    ");

        $result = $query->execute(array($id))
            ->fetchAll();

        $original_path = $result[0]['path'];
        $storage_id = str_replace("home::", "", $result[0]['id']) . '/';

        return $storage_id . $original_path;
    }

    protected function getLockpathFromCurrentUserFiles($path)
    {
        return \OCP\USER::getUser() . "/files" . Path::getClean($path);
    }

    /**
     * Must return an array with the first item having the key 'locked_by'
     * ex: db_lock_state[0]["locked_by"]
     *
     * @param $file
     * @param $fileType
     * @return mixed|null
     */
    protected function getLockStateForFile($file, $fileType)
    {
        $hasLock = Database::getFileLock($file);

        if ($hasLock != null) {
            return $hasLock;
        }

        if ($this->directoryLock === 'directory_locking_all') {
            return $this->checkFromAll($file);
        }

        return $this->checkFromParent($file, $fileType);
    }

    protected function getCurrentUserName($naming)
    {
        $lockedby_name = \OCP\User::getUser();

        if ($naming == "rule_displayname") {
            $lockedby_name = \OCP\User::getDisplayName();
            $lockedby_name .= "|" . \OCP\User::getUser();
        }

        return $lockedby_name;
    }

    protected function getUserThatLockedTheFile($db_lock_state)
    {
        return $db_lock_state[0]['locked_by'];
    }

    protected function showLockedByMessage($lockedby_name, $l)
    {
        return " " . $l->t("Locked") . " " . $l->t("by") . " " . $lockedby_name;
    }

    protected function currentUserIsTheOriginalLocker($owner)
    {
        return $owner == \OCP\User::getUser();
    }

    protected function getFilePath($id)
    {
        $query = "SELECT path FROM *PREFIX*" . "filecache" . " WHERE fileid = ?";

        $filePath = \OCP\DB::prepare($query)
            ->execute(array($id))
            ->fetchAll();

        return $filePath;
    }

    /**
     * Check if locked from all parents above.
     *
     * @param $file
     * @return mixed|null
     */
    protected function checkFromAll($file) {
        $currentPath = Path::removeLastDirectory($file);

        while ($currentPath) {
            $hasLock = Database::getFileLock($currentPath);

            if ($hasLock != null) {
                return $hasLock;
            }

            $currentPath = Path::removeLastDirectory($currentPath);
        }

        return null;
    }

    /**
     * Check if the current file is locked from direct parent directory only.
     * Only if it is a file (directories not allowed).
     *
     * @param $file
     * @param $fileType
     * @return mixed
     */
    protected function checkFromParent($file, $fileType) {
        if ($fileType !== 'file') {
            return null;
        }

        $currentPath = Path::removeLastDirectory($file);

        return Database::getFileLock($currentPath);
    }
}