<?php
/**
 * Расширение миграций для Yii
 *  Добавляет функционал запуска вложенных миграций.
 * @author Jasur Mirkhamidov <mirkhamidov.jasur@gmail.com>
 */
class MMigration extends CDbMigration 
{
    const DEFAULT_OPTION    = ' ENGINE=InnoDB DEFAULT CHARSET=utf8 ';
    const DEFAULT_ENGINE    = ' ENGINE=InnoDB ';
    const DEFAULT_CHARSET   = ' DEFAULT CHARSET=utf8 ';

    const SUBUP   = 'up';
    const SUBDOWN = 'down';

    protected $_tableNames = null;

    public function __get($name)
    {
        if( ( $_parent = $this->tryGetParent($name) )!==false)
        {
            return $_parent;
        }
        if ( $this->getTableName($name) ) return $this->getTableName($name);

        $reflector = new ReflectionClass(get_class($this));
        $inheritedPath = dirname($reflector->getFileName());
        if ( is_file( $inheritedPath.'/'.$name.'.php' ) ) {
            return include( $inheritedPath.'/'.$name.'.php' );
        } else {
            echo "Error [".__CLASS__."::".__FUNCTION__."]: File NOT Exists:".$inheritedPath.'/'.$name.".php\n";
            exit(1);
        }
        unset($reflector);
    }


    private function tryGetParent($name)
    {
        try
        {
            $_p = parent::__get($name);
            if($_p) return $_p;
            return false;
        } 
        catch (CException $e)
        {
            return false;
        }
    }

    protected function getTableNames()
    {

        if ( empty($this->_tableNames) ) {
            $_file = Yii::getPathOfAlias('application.migrations').'/tableNames.php';
            if ( is_file($_file) ) {
                $this->_tableNames = include($_file);
            } else return false;
        }
        return $this->_tableNames;
    }

    protected function getTableName($name)
    {
        $this->getTableNames();
        if ( isset($this->_tableNames[$name]) ) return $this->_tableNames[$name];
        return false;
    }

    /**
     * Выполнение SQL запросам массивом
     * 
     * @param array $arr Массив SQL запросов
     * @param bool $disableForeignKeyChecks Нужно ли отключать проверку внешних ключей
     */
    public function executeArray(&$arr, $disableForeignKeyChecks=true)
    {
        $arrC = sizeof($arr);
        if ( $arrC > 0 || !is_array($arr) ) {
            if ( $disableForeignKeyChecks ) $this->getDbConnection()->createCommand('SET FOREIGN_KEY_CHECKS=0;')->execute();
            for( $i=0; $i<$arrC; ++$i ){
                $this->execute( $arr[$i] );
            }
            if ( $disableForeignKeyChecks ) $this->getDbConnection()->createCommand('SET FOREIGN_KEY_CHECKS=1;')->execute();
        } else return false;
    }
    

    /**
     * Получить название текущей БД
     */
    public function getDBname()
    {
        preg_match("/dbname=([^;]*)/", Yii::app()->components['db']->connectionString,$matches );
        return $matches[1];
    }

    /**
     * Добавление столбца к таблице с проверкой на ее существование
     * Иначе будет все отваливаться
     * 
     * @param string $table         название таблицы без всякиъ там кавычек и тд
     * @param string $column        название добавляемого поля, без всякиъ там кавычек и тд
     * @param string $collumnOpt    описание  к полю, там кодировка и тд
     */
    public function addColumn($table,$column,$collumnOpt)
    {
        $dbname = self::getDBname();
        $table = $this->trimTableName($table);

        if ( $this->isColumnExists($table, $column) ) {
            parent::alterColumn($table, $column, $collumnOpt);
        } else {
            parent::addColumn($table, $column, $collumnOpt);
        }
    }

    /**
     * Удаление столбца из таблице с проверкой на ее существование
     * 
     * @param string $table         название таблицы без всякиъ там кавычек и тд
     * @param string $column        название удаляемого поля, без всякиъ там кавычек и тд
     */
    public function dropColumn($table,$column)
    {
        $dbname = self::getDBname();
        $table = $this->trimTableName($table);
        if ( $this->isColumnExists($table, $column) ) {
            parent::dropColumn($table, $column);
        } else {
            echo "    > drop column $column from table $table NOTHING to drop\n";
        }
    }

    /**
     * Проверяет на существование запрашиваемого столбца
     * 
     * @param string $table 
     * @param string $column
     * 
     * @return bool
     */
    public function isColumnExists($table, $column)
    {
        $table = $this->trimTableName($table);
        $dbname = self::getDBname();
        return (bool)$this->getDbConnection()->createCommand("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE COLUMN_NAME='{$column}' AND TABLE_NAME='{$table}' AND TABLE_SCHEMA='{$dbname}'")->queryScalar();
    }

    /**
     * Создание таблицы (перегрузка стандартной ф-ии)
     * 
     * @param string $table Название таблицы
     * @param array $columns Необходимые поля
     * @param string $options Дополнительные опции
     * @param bool $dropIfExists Удалить таблицу если существует до создания
     */
    public function createTable($table, $columns, $options=NULL, $dropIfExists=false, $disableForeignKeyChecks=true)
    {
        if ( $disableForeignKeyChecks ) $this->getDbConnection()->createCommand('SET FOREIGN_KEY_CHECKS=0;')->execute();
        if ( $dropIfExists ) {
            $time=microtime(true);
            echo "    > before create $table -> drop first ...";
            $this->getDbConnection()->createCommand('DROP TABLE IF EXISTS '.$this->trimTableName($table).';')->execute();
            echo " done (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
        }
        if ( empty($options) ) $options=self::DEFAULT_OPTION;
            else {
                if ( stristr($options, 'engine') === false ) $options .= self::DEFAULT_ENGINE;
                if ( stristr($options, 'charset') === false ) $options .= self::DEFAULT_CHARSET;
            }
        parent::createTable($table,$columns,$options);
        if ( $disableForeignKeyChecks ) $this->getDbConnection()->createCommand('SET FOREIGN_KEY_CHECKS=1;')->execute();
    }

    /**
     * Удаление таблицы (перегрузка стандартной ф-ии)
     * 
     * @param string $table Название таблицы
     * @param bool $disableForeignKeyChecks Отключать проверку внешних ключей
     */
    public function dropTable($table, $disableForeignKeyChecks=false)
    {
        echo "    > drop table $table ...";
        if ( $disableForeignKeyChecks ) $this->getDbConnection()->createCommand('SET FOREIGN_KEY_CHECKS=0;')->execute();
        $time=microtime(true);
        $sql = 'DROP TABLE IF EXISTS '.$table.';';
        $this->getDbConnection()->createCommand($sql)->execute();
        if ( $disableForeignKeyChecks ) $this->getDbConnection()->createCommand('SET FOREIGN_KEY_CHECKS=1;')->execute();
        echo " done (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
    }


    /**
     * Приводит имя таблицы в нормальный вид с префиксом
     * 
     * @param string 
     * 
     * @return string
     */
    public function trimTableName($tableName)
    {
        return preg_replace('/{{(.*?)}}/',Yii::app()->db->tablePrefix.'\1',$tableName);
    }

    /**
     * Запуск дополнительных миграций
     * 
     * @access public
     * 
     * @param array $_args      Указать дополнительные аргументы строки
     * @param bool $_null_args  Если необходимо заново указать аргументы через $_args
     */
    public function runSubMigration($_args=array(), $_null_args=false)
    {
        $runner = new CConsoleCommandRunner();
        $runner->commands = Yii::app()->commandMap;
        $args = array('yiic', 'migrate', '--interactive=0', '--issub=1');
        if ( $_null_args === true ) {
            $args = $_args;
        } elseif( count($_args) > 0 ) {
            $args = array_merge($args, $_args);
        }
        $runner->run($args);
    }

    /**
     * Выполнение sub миграций с правильным выполнением статусов
     * Использовать через:
     *      migrateSub
     *      migrateDownSub
     * @access private 
     * @param string    $dir            Выполнение или откат
     * @param string    $aliasOfPath    Путь до миграций в модуле, в виде алиса
     * @param array     $migrationsList Название классов миграций в порядке котором необходимо выполнить. При откате массив выполняется в обратном порядке
     */
    private function runSubMigrate($dir, $aliasOfPath, &$migrationsList)
    {
        $_pathOfAlias = Yii::getPathOfAlias($aliasOfPath);
        if(!is_dir($_pathOfAlias))
        {
            echo "\n\tError: Указанный алиас не является директорией!\n\n";
            return false;
        }

        $migrationsListCount = count($migrationsList);
        if($migrationsListCount==0)
        {
            echo "\n\tError: Нет вложенных миграций!\n\n";
            return false;
        }
        $_runTransaction = !Y::isTransactionOn();
        if ( $_runTransaction ) $transaction=$this->getDbConnection()->beginTransaction();

        $_cMigrateRunCommand    = $dir==self::SUBUP ? 'up'          : 'down';
        $_mStartRun             = $dir==self::SUBUP ? 'выполнение'  : 'откат';
        $_mFinishSuccess        = $dir==self::SUBUP ? 'выполнен'    : 'откат выполнен';
        $_mFinishFail           = $dir==self::SUBUP ? 'выполнении'  : 'выполнении отката';

        $err=0;

        // Если откат, то в обратном порядке
        if($dir==self::SUBDOWN)
        {
            $migrationsList = array_reverse($migrationsList);
        }

        /**
         * Простой вывод с табуляцией
         */
        $obFlush = function()
        {
            $buffer = ob_get_clean();
            $buffer = strtr($buffer, array("    "=>"\t\t") );
            echo $buffer;
        };

        try
        {
        
            for($i=0; $i<$migrationsListCount; ++$i)
            {
                $class = $migrationsList[$i];
                echo "\t*** выполнение $class\n";
                ob_start();
                $start=microtime(true);


                $migration = $this->instantiateSubMigration($_pathOfAlias, $class);
                if($migration->{$_cMigrateRunCommand}()!==false)
                {
                    if($dir==self::SUBUP)
                    {
                        $this->getDbConnection()->createCommand()->insert($this->myMigrateCommand->migrationTable, array(
                            'version'           =>$class,
                            'apply_time'        =>time(),
                            'issub'             =>1,
                            'migration_path'    =>$this->myMigrateCommand->makeUpMigrationPath($_pathOfAlias),
                        ));
                    }
                    else
                    {
                        $db=$this->getDbConnection();
                        $db->createCommand()->delete($this->myMigrateCommand->migrationTable, 
                            $db->quoteColumnName('version').'=:version AND issub=1 AND '.$db->quoteColumnName('migration_path').'=:migrationPath', 
                            array(
                                ':version'          =>$class,
                                ':migrationPath'    =>$this->myMigrateCommand->makeUpMigrationPath($_pathOfAlias),
                            )
                        );
                    }
                    $time=microtime(true)-$start;
                    $obFlush();
                    echo "\t*** $_mFinishSuccess $class (время выполнения: ".sprintf("%.3f",$time)."s)\n\n";
                }
                else
                {
                    $time=microtime(true)-$start;
                    $obFlush();
                    echo "\t*** ошибка при $_mFinishFail $class. Процесс прерван! (время выполнения: ".sprintf("%.3f",$time)."s)\n\n";
                    ++$err;
                    break;
                }
            }
        } 
        catch (Exception $e)
        {
            echo "Exception: ".$e->getMessage()."\n";
            ++$err;
        }

        if($err>0)
        {
            if ( $_runTransaction ) $transaction->rollBack();
            $obFlush();
            echo "\tError: В ходе выполнения sub миграций возникли ошибки. Отмена транзакции.\n";
            return false;
        }
        else
        {
            if ( $_runTransaction ) $transaction->commit();
            return true;
        }
        
        return false;
    }

    /**
     * Выполнение sub миграций
     * более подробно см.$this->runSubMigrate
     */
    public function migrateSub($aliasOfPath, &$migrationsList)
    {
        return $this->runSubMigrate(self::SUBUP, $aliasOfPath, $migrationsList);
    }

    /**
     * Откат sub миграций
     * более подробно см.$this->runSubMigrate
     */
    public function migrateDownSub($aliasOfPath, &$migrationsList)
    {
        return $this->runSubMigrate(self::SUBDOWN, $aliasOfPath, $migrationsList);
    }

    /**
     * Пример реализации вязт из MigrateCommand::instantiateMigration
     */
    protected function instantiateSubMigration($path, $class)
    {
        $file=$path.DIRECTORY_SEPARATOR.$class.'.php';
        require_once($file);
        $migration=new $class;
        $migration->setDbConnection($this->getDbConnection());
        return $migration;
    }

    /**
     * @access protected
     * @return object of MMigrateCommand
     */
    protected function getMyMigrateCommand()
    {
        return Yii::app()->getCommand();
    }


    /**
     * Импорт дамп файлов
     * 
     * @access public
     * @param String $pathToFile
     * @param Bool $dropTableIfExists
     */
    public function importDumpFile($pathToFile, $dropTableIfExists=false)
    {
        echo "    > import dump file $pathToFile";
        $time=microtime(true);
        if ( is_file($pathToFile) ) {
            $out = array();
            exec('cat '.$pathToFile, $out);
            ob_start();
            echo implode("\n\r", $out);
            $_t = ob_get_clean();

            if ( $dropTableIfExists ) {
                $cl = array('`'=>'');
                preg_match_all('|CREATE TABLE(.*)\(|', $_t, $matches);

                if ( !empty($matches[1])) {
                    for( $i=0, $max=count($matches[1]); $i<$max; ++$i ) {
                        $_table = trim( strtr($matches[1][$i], $cl) );
                        echo "\n      > drop table $_table ...";
                        $this->getDbConnection()->createCommand('DROP TABLE IF EXISTS '.$_table)->execute();
                        echo "done";
                    }
                }
            }

            $res = $this->getDbConnection()->createCommand($_t)->execute();
            echo "\n    > done import dump (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
            return true;
        } else {
            echo "\n    FAIL (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
            return false;
        }
    }

    /**
     * Удаление таблицы из дамп файла
     * 
     * @access public
     * @param String $pathToFile
     */
    public function dropTablesFromDumpFile($pathToFile)
    {
        $cl = array('`'=>'');
        echo "    > drop tables from dump file $pathToFile";
        $time=microtime(true);
        if ( is_file($pathToFile) ) {
            $out = array();
            exec('cat '.$pathToFile, $out);
            ob_start();
            echo implode("\n\r", $out);
            $_t = ob_get_clean();
            
            preg_match_all('|CREATE TABLE(.*)\(|', $_t, $matches);
            if ( !empty($matches[1])) {
                for( $i=0, $max=count($matches[1]); $i<$max; ++$i ) {
                    $_table = trim( strtr($matches[1][$i], $cl) );
                    echo "\n      > drop table $_table ...";
                    $this->getDbConnection()->createCommand('DROP TABLE IF EXISTS '.$_table)->execute();
                    echo "done";
                }
            }
            echo "\n    > done drop tables from dump file (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
            return true;
        } else {
            echo "\n    FAIL (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
            return false;
        }
    }

    /**
     * Удаление внешнего ключа с проверкой на его существование
     * @param string $name the name of the foreign key constraint to be dropped. The name will be properly quoted by the method.
     * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
     */
    public function dropForeignKey($name, $table)
    {
        echo "    > drop foreign key $name from table $table ...";
        $time=microtime(true);
        $dbName = $this->getDBname();
        $sql = "SELECT TRUE
         FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
             AND TABLE_SCHEMA = '$dbName'
             AND CONSTRAINT_NAME = '$name'";
        if ( $this->getDbConnection()->createCommand($sql)->queryScalar() ) {
            $this->getDbConnection()->createCommand()->dropForeignKey($name, $table);
            echo " done (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
        } else {
            echo " [SKIPPING] (FK \"$name\" does not exist) (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
        }

    }

        /**
     * Создание триггера
     * @param string $name          Просто название триггера
     * @param string $tableName     К какой таблице применять триггер
     * @param string $event         при каком событии, можно применять INSERT, UPDATE, DELETE
     * @param string $stmt          Выполнение триггера
     * @param string $time          BEFORE | AFTER
     * @param bool $drop            Удалять перед созданием
     */
    public function createTrigger($name, $tableName, $event, $stmt, $ttime='AFTER', $drop=true)
    {
        echo "  > create trigger '$name' ... ";
        $time=microtime(true);
        $createTriggerSql = <<< SQL
CREATE
    TRIGGER `{$name}` {$ttime} {$event} ON `{$tableName}`
    FOR EACH ROW BEGIN

    {$stmt}
END;
SQL;
        $this->getDbConnection()->createCommand('DROP TRIGGER /*!50032 IF EXISTS */ `'.$name.'`')->execute();
        $this->getDbConnection()->createCommand($createTriggerSql)->execute();
        echo " done (time: ".sprintf('%.3f', microtime(true)-$time)."s) \n";
    }


    /**
     * Перегузка стандартного метода
     * @param string $table см.в API
     * @param Array $columns см.в API
     * @param bool $update Необходимо ли обновлять записи если есть совпадение
     * @param mixed $updateColumn Поле которое необходимо обновить
     */
    public function insert($table, $columns, $update=false, $updateColumn=null)
    {
        if ( $update === false ) {
            return parent::insert($table,$columns);
        } else {
            // Взято из CDbCommand->insert
            echo "    > insert into (on duplicate key update) $table ...";
            $time=microtime(true);

            $params=array();
            $names=array();
            $placeholders=array();
            foreach($columns as $name=>$value)
            {
                $names[]=$this->getDbConnection()->quoteColumnName($name);
                if($value instanceof CDbExpression)
                {
                    $placeholders[] = $value->expression;
                    foreach($value->params as $n => $v)
                        $params[$n] = $v;
                }
                else
                {
                    $placeholders[] = ':' . $name;
                    $params[':' . $name] = $value;
                }
            }

            $_updCol = array();

            if ( $updateColumn === null ) {
                reset($names);
                $updateColumn = current($names);
                $_updCol[] = $updateColumn.'=VALUES('.$updateColumn.')';
            } elseif( is_array($updateColumn) ) {
                for( $i=0,$max=count($updateColumn); $i<$max; ++$i ) {
                    $_updCol[] = $updateColumn[$i].'=VALUES('.$updateColumn[$i].')';
                }
            } else {
                $_updCol[] = $updateColumn.'=VALUES('.$updateColumn.')';
            }

            $sql='INSERT INTO ' . $this->getDbConnection()->quoteTableName($table)
                . ' (' . implode(', ',$names) . ') VALUES ('
                . implode(', ', $placeholders) . ') ON DUPLICATE KEY UPDATE '.implode(', ', $_updCol);
            echo " done (time: ".sprintf('%.3f', microtime(true)-$time)."s)\n";
            return $this->getDbConnection()->createCommand($sql)->execute($params);
        }
    }

    // private function readStdin($prompt, $valid_inputs, $default = '') {
    //  while(!isset($input) || (is_array($valid_inputs) && !in_array($input, $valid_inputs)) || ($valid_inputs == 'is_file' && !is_file($input))) {
    //      echo $prompt;
    //      $input = strtolower(trim(fgets(STDIN)));
    //      if(empty($input) && !empty($default)) {
    //          $input = $default;
    //      }
    //  }
    //  return $input;
    // }

    // private function readStdinUser($prompt, $field, $default = '') {
    //  if (!$this->_model)
    //      $this->_model = new User;

    //  while(!isset($input) || !$this->_model->validate(array($field))) {
    //      echo $prompt.(($default)?" [$default]":'').': '; 
    //      $input = (trim(fgets(STDIN)));
    //      if(empty($input) && !empty($default)) {
    //          $input = $default;
    //      }
    //      $this->_model->setAttribute($field,$input);
            
    //  }
    //  return $input;
    // }
    
    /*
    * Метод для неконфликтного импорта данных из sql-файла
    * @param string $path - алиас до места хранения дампов
    * @param string $tableName - название таблицы в дампе и алиас модуля
    * @return void
    */
    public function importData($path, $tableName) {
        
        if ($path[(mb_strlen($path) - 1)] != '.')
            $path .= '.';
        
        $dumpPath = Yii::getPathOfAlias($path.$tableName);
        $this->importDumpFile($dumpPath.DIRECTORY_SEPARATOR.'schema.sql', true);
        $this->importDumpFile($dumpPath.DIRECTORY_SEPARATOR.'data.sql');
        
        $columns = Yii::app()->db->createCommand('SHOW COLUMNS FROM '.$this->tables[$tableName])->queryColumn();
        $columns_str = implode(',', $columns);
        $sql = "INSERT INTO ".$this->tables[$tableName]." (".$columns_str.") SELECT ".$columns_str." FROM ".$tableName.";";
        $this->getDbConnection()->createCommand($sql)->execute();

        $this->dropTablesFromDumpFile($dumpPath.DIRECTORY_SEPARATOR.'schema.sql');
        
    }
}