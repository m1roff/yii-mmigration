<?php

Yii::import('system.cli.commands.MigrateCommand');
class MMigrateCommand extends MigrateCommand
{
    public $issub=0;
    private $_ob;

    /**
     * @var string the path of the template file for generating new init migrations. This
     * must be specified in terms of a path alias (e.g. application.migrations.template).
     * If not set, an internal template will be used.
     */
    public $templateInitFile=null;

    private $_bufReplace = array("\n"=>"\n   |");

    public function beforeAction($action,$params)
    {
        if ( $this->issub ) {
            ob_start(array(__CLASS__,'obflush'));
        }
        return parent::beforeAction($action,$params);
    }

    public function afterAction($action,$params,$exitCode=0)
    {
        if ( ob_get_level() ) {
            $this->_ob = ob_get_clean();
            echo strtr($this->_ob, $this->_bufReplace );
        }
        $_return = parent::afterAction($action,$params,$exitCode);
        return $_return;
    }

    public function obflush($buffer)
    {
        $buffer = strtr($buffer, $this->_bufReplace );
        return $buffer;
    }

    protected function checkDatabaseEncoding()
    {
        $db=$this->getDbConnection();
        echo 'Checking database character ...';
        $_res = $db->createCommand('SHOW VARIABLES LIKE "character\_set\_database";')->queryRow();
        
        if ( !empty($_res) && $_res['Value'] != 'utf8' ) {
            $_dbName = $db->createCommand('SELECT DATABASE() FROM DUAL;')->queryScalar();
            $db->createCommand('ALTER DATABASE '.$_dbName.' CHARACTER SET utf8 COLLATE utf8_general_ci;')->execute();
            echo ' converted to utf8 ... ';
        } else {
            echo ' already utf8 ... ';
        }
        echo "done.\n";
    }

    protected function createMigrationHistoryTable()
    {
        $this->checkDatabaseEncoding();
        $db=$this->getDbConnection();
        echo 'Creating migration history table "'.$this->migrationTable.'"...';
        $db->createCommand()->createTable($this->migrationTable,array(
            'version'           => 'string NOT NULL PRIMARY KEY',
            'apply_time'        => 'integer',
            'migration_path'    => 'string default null',
            'issub'             => 'tinyint default 0',
        )
        ,'ENGINE=InnoDB DEFAULT CHARSET=utf8 '
        );
        $db->createCommand()->insert($this->migrationTable,array(
            'version'=>self::BASE_MIGRATION,
            'apply_time'=>time(),
        ));
        echo "done.\n";
    }

    protected function migrateUp($class)
    {
        if($class===self::BASE_MIGRATION)
            return;

        echo "*** applying $class\n";

        $start=microtime(true);
        $migration=$this->instantiateMigration($class);
        if($migration->up()!==false)
        {
            $this->getDbConnection()->createCommand()->insert($this->migrationTable, array(
                'version'           => $class,
                'apply_time'        => time(),
                'migration_path'    => $this->makeUpMigrationPath( $this->migrationPath ),
                'issub'             => $this->issub,
            ));
            $time=microtime(true)-$start;
            echo "*** applied $class (time: ".sprintf("%.3f",$time)."s)\n\n";
        }
        else
        {
            $time=microtime(true)-$start;
            echo "*** failed to apply $class (time: ".sprintf("%.3f",$time)."s)\n\n";
            return false;
        }
    }

    protected function migrateDown($class)
    {
        if($class===self::BASE_MIGRATION)
            return;

        echo "*** reverting $class\n";
        $start=microtime(true);
        $migration=$this->instantiateMigration($class);
        if($migration->down()!==false)
        {
            $db=$this->getDbConnection();
            $db->createCommand()->delete($this->migrationTable, $db->quoteColumnName('version').'=:version', array(':version'=>$class));
            $time=microtime(true)-$start;
            echo "*** reverted $class (time: ".sprintf("%.3f",$time)."s)\n\n";
        }
        else
        {
            $time=microtime(true)-$start;
            echo "*** failed to revert $class (time: ".sprintf("%.3f",$time)."s)\n\n";
            return false;
        }
    }

    protected function instantiateMigration($class)
    {
        $caller = null;
        $_callstack = debug_backtrace(false,2);
        if(isset($_callstack[1]['function'])) $caller = $_callstack[1]['function'];
        if ( $caller == 'migrateUp' ) $file=$this->migrationPath.DIRECTORY_SEPARATOR.$class.'.php';
            else {
                $file=$this->makeDownMigrationPath($class).DIRECTORY_SEPARATOR.$class.'.php';
            }
        require_once($file);
        $migration=new $class;
        $migration->setDbConnection($this->getDbConnection());
        return $migration;
    }

    public function makeUpMigrationPath($path)
    {
        return strtr($path, array(Yii::app()->getBasePath()=>''));
    }

    protected function makeDownMigrationPath($class)
    {
        $_path = $this->getDbConnection()->createCommand('SELECT migration_path FROM '.$this->migrationTable.' WHERE version="'.$class.'"')->queryScalar();
        if ( $_path ) {
            return Yii::app()->getBasePath().$_path;
        } else {
            echo "\nError[".__CLASS__."::".__FUNCTION__."]: Cant get 'migration_path' for the class '$class'\n";
            exit(1);
        }
        
    }

    protected function getMigrationHistory($limit)
    {
        $db=$this->getDbConnection();
        if($db->schema->getTable($this->migrationTable,true)===null)
        {
            $this->createMigrationHistoryTable();
        }
        return CHtml::listData($db->createCommand()
            ->select('version, apply_time')
            ->from($this->migrationTable)
            ->where('issub=:issub', array(':issub'=>$this->issub))
            ->order('version DESC')
            ->limit($limit)
            ->queryAll(), 'version', 'apply_time');
    }


    /**
     * Создание sub миграций из указанной директории(алиас)
     * Расширен базовый вызов MigrateCommand::actionCreate
     */
    public function actionCreateInit($args, $name=null)
    {
        if(isset($args[0]))
            $alias=$args[0];
        else
            $this->usageError('Please provide the alias of the new init migration.');

        $_pathOfAlias = Yii::getPathOfAlias($alias);
        if($_pathOfAlias===false) 
        {
            echo "Error: Указан некорректный алиас пути.\n";
            return 1;
        }
        elseif(!is_dir($_pathOfAlias))
        {
            echo "Error: Указанный алиас не является директорией.\n";
            return 1;
        }

        // необходим для проверки, былоли уже реализована миграция
        $_inMigrationsHistory=array('tables.php');
        // Получить историю миграций
        $_migrationsHistory = Yii::app()->db->createCommand()
                ->select('version, apply_time')
                ->from($this->migrationTable)
                ->where('issub=1 AND migration_path=:migrationPath', array(':migrationPath'=>$this->makeUpMigrationPath($_pathOfAlias)))
                ->queryAll();
        $_migrationsHistoryCount = count($_migrationsHistory);
        if($_migrationsHistoryCount>0)
        {
            // Необходимо привести в удобный вид для дальнейшей проверки
            for($i=0; $i<$_migrationsHistoryCount; ++$i)
            {
                $_inMigrationsHistory[] = $_migrationsHistory[$i]['version'].'.php';
            }
            unset($_migrationsHistory);
        }
        
        // Получим список новых миграций, которые необходимо запустить
        $findOptions = array(
                'fileTypes' =>array('php'),
                'level'     =>0,
                'exclude' => $_inMigrationsHistory
            );
        $_newSubMigrationsFiles = CFileHelper::findFiles($_pathOfAlias, $findOptions);
        $_newSubMigrationsFilesCount = count($_newSubMigrationsFiles);
        $_subExists = 0;
        if($_newSubMigrationsFilesCount==0)
        {
            echo "Warning: По указанному алиасу новые миграции не найдены!.\n\n";
            // return 1;
        }
        else
        {
            $_newSubMigrations = array();
            set_error_handler(array('MMigrateCommand', 'tempErrorHandler'), error_reporting());
            for($i=0; $i<$_newSubMigrationsFilesCount; ++$i)
            {
                $_subMigrateFileClass = basename($_newSubMigrationsFiles[$i], '.php');
                if(!preg_match('/^m[0-9]{6}_[0-9]{6}_/', $_subMigrateFileClass)) continue;

                include($_newSubMigrationsFiles[$i]);
                try
                {
                    $_subInstance = new $_subMigrateFileClass;
                    if($_subInstance instanceof CDbMigration) 
                    {
                        $_newSubMigrations[] = $_subMigrateFileClass;
                        ++$_subExists;
                    }
                } catch (CException $e)
                {
                    continue;
                }
                
            }
            restore_error_handler();
            if($_subExists==0)
            {
                echo "Warning: По указанному алиасу новые миграции не найдены!.\n\n";
                echo $this->getHelp()."\n";
                return 1;
            }
        }
        
        // Генерация имени инит миграции
        $name = ($name===null ? strtr($alias, array('.'=>'_')) : $name).'_init';
        $_nameInit='m'.gmdate('ymd_His').'_'.$name;

        // Если есть схожее имя файла, то предложем ввести другое название файла
        if( ($_exists=$this->isInitMigrationExists($name))!==false)
        {
            echo "Warning: Найдены схожые инит миграции (выберите другое название для миграции):\n";
            for($i=0, $max=count($_exists); $i<$max; ++$i)
            {
                echo "\t- $_exists[$i]\n";
            }
            return 1;
        }

        // Получить шаблон для создания init миграции
        $content=strtr($this->getInitTemplate(), array('{ClassName}'=>$_nameInit, '{MigrationsPath}' => $alias, '{SubMigrationsList}'=>$this->exportVar($_newSubMigrations)));
        // echo $content;

        $file=$this->migrationPath.DIRECTORY_SEPARATOR.$_nameInit.'.php';
        $_forMsg = $_subExists>0 ? $this->exportVar($_newSubMigrations, false) : "\tНет новых, вложенных миграций";
        $_configMsg = <<<EOD
Будет создана инит миграция:
\t '$file'
С sub миграциями из директории '$_pathOfAlias':
$_forMsg
Продолжить создание?
EOD;

        if($this->confirm($_configMsg))
        {
            file_put_contents($file, $content);
            echo "Успешное создание новой инит миграции.\n";
        }
    }

    /**
     * Экспорт значения массива в виде удобной для использования в коде
     * @access private
     * @param Array $array  Массив который необходимо "вывести"
     * @param bool  $asVar  true - удобной для исползования в коде (php-код); false-в виде строки, для stdout;
     * @return string
     */
    private function exportVar(&$array, $asVar=true)
    {
        $_return = '';
        if($asVar) $_return .= "array(\n";
        for($i=0, $max=count($array); $i<$max; ++$i)
        {
            if($asVar) $_return .= "\t\t'$array[$i]',\n";
                else $_return .= "\t'$array[$i]'\n";
        }
        if($asVar) $_return .="\t)";
        return $_return;
    }

    /**
     * Проверка существования инит миграции
     * @access private
     * @param string @name Название файла без маски с датой
     * @return mixed false-если файла нет, array название совпавшего файла 
     */
    private function isInitMigrationExists($name)
    {
        $_pattern = $this->migrationPath.DIRECTORY_SEPARATOR.'*'.$name.'.php';
        $_founded = glob($_pattern);
        if($_founded!=array())
        {
            $_r = array();
            for($i=0, $max=count($_founded); $i<$max; ++$i)
            {
                $_r[] = basename($_founded[$i]);
            }
            return $_r;
        }
        else return false;
    }

    /**
     * Дополнение раздела помощи
     */
    public function getHelp()
    {
        $_p = parent::getHelp();
        $_p .= <<<EOD
\n * yiic migrate createInit application.modules.migrations [--name='Название_инит_миграции']
   Важно! не использовать методы safe[Up|Down]!
   Создать инит(главную) миграцию по отношению к вложенным миграциям в модулях.
   Если в модулях созданы новые миграции, то при создании они добавятся автоматически.
   \tГде:
   \t  application.modules.migrations - путь до директории с миграциями модуля в виде алиаса
   \t  name - необязательный параметр. Указать название инит миграции. Если не указать
   \t         то названием будет указанный алиса (Пр.:"m999999_999999_application_modules_migrations_init").
EOD;
        
        return $_p;
    }

    /**
     * Получить шаблон файла для генерации init миграции
     */
    protected function getInitTemplate()
    {
        if($this->templateInitFile!==null)
            return file_get_contents(Yii::getPathOfAlias($this->templateInitFile).'.php');
        else
            return <<<EOD
<?php

class {ClassName} extends DMigration
{
    private \$inPath='{MigrationsPath}';
    private \$subMigrations={SubMigrationsList};
    public function up()
    {
        if( !\$this->migrateSub(\$this->inPath, \$this->subMigrations) )
        {
            return false;
        }
    }

    public function down()
    {
        if( !\$this->migrateDownSub(\$this->inPath, \$this->subMigrations) )
        {
            return false;
        }
    }

}

EOD;
    }

    public static function tempErrorHandler($errno, $errstr)
    {
        return true;
    }

}