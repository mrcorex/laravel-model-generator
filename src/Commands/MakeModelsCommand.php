<?php

namespace CoRex\Generator\Commands;

use CoRex\Generator\Helpers\Convention;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class MakeModelsCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate model(s) from existing schema';

    /**
     * Default model namespace.
     *
     * @var string
     */
    protected $namespace = 'Models/';

    /**
     * Default class the model extends.
     *
     * @var string
     */
    protected $extends = 'Illuminate\Database\Eloquent\Model';

    /**
     * Rules for columns that go into the guarded list.
     *
     * @var array
     */
    protected $guardedRules = 'ends:_guarded'; //['ends' => ['_id', 'ids'], 'equals' => ['id']];

    /**
     * Rules for columns that go into the fillable list.
     *
     * @var array
     */
    protected $fillableRules = '';

    /**
     * Rules for columns that set whether the timestamps property is set to true/false.
     *
     * @var array
     */
    protected $timestampRules = 'ends:_at'; //['ends' => ['_at']];

    /**
     * Indent for code.
     *
     * @var string
     */
    protected $indent = '    ';

    /**
     * Preserved identifier.
     *
     * @var string
     */
    protected $preserved = '/* ---- Everything after this line will be preserved. ---- */';

    /**
     * Stub.
     *
     * @var
     */
    private $stub;

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function fire()
    {
        if (config('corex.laravel-model-generator.path') === null) {
            $message = 'You must set up path. [corex.laravel-model-generator.path].';
            throw new \Exception($message);
        }
        if (config('corex.laravel-model-generator.namespace') === null) {
            $message = 'You must set up namespace. [corex.laravel-model-generator.namespace].';
            throw new \Exception($message);
        }
        if (config('corex.laravel-model-generator.databaseSubDirectory') === null) {
            $message = 'You must set true/false if models for database should go into separate directories.';
            $message .= '[corex.laravel-model-generator.databaseSubDirectory].';
            throw new \Exception($message);
        }
        if (config('corex.laravel-model-generator.tablePublic') === null) {
            $message = 'You must set true/false if table property should be public after generation.';
            $message .= '[corex.laravel-model-generator.tablePublic].';
            throw new \Exception($message);
        }
        if (config('corex.laravel-model-generator.extends') === null) {
            $message = 'You must specify extends. [corex.laravel-model-generator.extends].';
            throw new \Exception($message);
        }

        $database = $this->argument('database');
        $tables = $this->argument('tables');
        $guardedFields = $this->option('guarded');
        if ($guardedFields === null) {
            $guardedFields = '';
        }
        $guardedFields = explode(',', $guardedFields);
        $this->stub = file_get_contents($this->getStub());

        // Tables.
        if ($tables != '.') {
            $tables = explode(',', $tables);
        } else {
            $tables = $this->getTables($database);
        }

        // Make models.
        if (count($tables) > 0) {
            foreach ($tables as $table) {
                $this->makeModel($database, $table, $guardedFields);
            }
        }
    }

    /**
     * Make model.
     *
     * @param string $database
     * @param string $table
     * @param string $guardedFields
     * @throws \Exception
     */
    protected function makeModel($database, $table, $guardedFields)
    {
        $filename = $this->buildFilename($database, $table);
        $this->makeDirectory($filename);
        $preservedLines = $this->getPreservedLines($filename);
        $classContent = $this->replaceTokens($database, $table, $preservedLines, $guardedFields);
        if ($classContent != '') {
            $this->files->put($filename, $classContent);
            $this->info('Model [' . $filename . '] created.');
        } else {
            $this->info('Table [' . $table . '] does not exist.');
        }
    }

    /**
     * Replace tokens.
     *
     * @param string $database
     * @param string $table
     * @param array $preservedLines
     * @param string $guardedFields
     * @return mixed|string
     */
    protected function replaceTokens($database, $table, array $preservedLines, $guardedFields)
    {
        $class = $this->buildClassName($table);
        $namespace = $this->buildNamespace($database);
        $extends = $this->getExtend();
        $stub = $this->stub;

        $properties = $this->getTableProperties($database, $table, $guardedFields);
        if (count($properties['fillable']) == 0) {
            return '';
        }

        $stub = str_replace('{{namespace}}', $namespace, $stub);

        $stub = str_replace('{{extends}}', $extends, $stub);

        $stub = str_replace('{{class}}', $class, $stub);

        $docProperties = $this->getDocProperties($database, $table, $properties['fillable']);
        $stub = str_replace('{{properties}}', implode("\n", $docProperties), $stub);

        $classParts = explode('\\', $extends);
        $model = end($classParts);
        $stub = str_replace('{{shortNameExtends}}', $model, $stub);

        $stub = str_replace(
            '{{connection}}',
            $this->indent . 'protected $connection = \'' . $database . '\';' . "\n\n",
            $stub
        );

        $tableVisiblity = 'protected';
        if (config('corex.laravel-model-generator.tablePublic')) {
            $tableVisiblity = 'public';
        }
        $stub = str_replace(
            '{{table}}',
            $this->indent . $tableVisiblity . ' $table = \'' . $table . '\';' . "\n\n",
            $stub
        );

        $primaryKey = '';
        if ($properties['primaryKey']) {
            $primaryKey = $this->indent . 'protected $primaryKey = \'' . $properties['primaryKey'] . '\';' . "\n\n";
        }
        $stub = str_replace('{{primaryKey}}', $primaryKey, $stub);

        $timestamps = $properties['timestamps'] ? 'true' : 'false';
        $stub = str_replace(
            '{{timestamps}}',
            $this->indent . 'public $timestamps = ' . $timestamps . ';' . "\n\n",
            $stub
        );

        $fillable = 'protected $fillable = ' . $this->convertArrayToString($properties['fillable']);
        $stub = str_replace('{{fillable}}', $this->indent . $fillable . ';' . "\n\n", $stub);

        $guarded = $this->convertArrayToString($properties['guarded']);
        $stub = str_replace('{{guarded}}', $this->indent . 'protected $guarded = ' . $guarded . ';' . "\n\n", $stub);

        if (count($preservedLines) > 0) {
            $stub = str_replace($this->preserved, $this->preserved . "\n" . implode("\n", $preservedLines), $stub);
        }

        return $stub;
    }

    /**
     * Get stub file location.
     *
     * @return string
     */
    public function getStub()
    {
        return dirname(dirname(__DIR__)) . '/stubs/model.stub';
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['database', InputArgument::REQUIRED, 'Name of database.'],
            ['tables', InputArgument::REQUIRED, 'Comma separated table names to generate. Specify "." to generate all.']
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['guarded', null, InputOption::VALUE_OPTIONAL, 'Comma separated list of guarded fields.', null]
        ];
    }

    /**
     * Get list of tables.
     *
     * @param string $database
     * @return mixed
     * @throws \Exception
     */
    private function getTables($database)
    {
        $result = [];
        $driver = $this->getDatabaseDriver($database);
        switch ($driver) {
            case 'mysql':
                $tables = \DB::connection($database)->select("SHOW TABLES");
                break;

            case 'sqlsrv':
                throw new \Exception('Not implemented yet.');
                break;

            case 'sqlite':
                throw new \Exception('Not implemented yet.');
                break;

            case 'postgres':
                throw new \Exception('Not implemented yet.');
                break;

            default:
                throw new \Exception('Connection-driver [' . $driver . '] not supported.');
                break;
        }

        // Extract and convert to array.
        if (count($tables) > 0) {
            foreach ($tables as $table) {
                $table = (array)$table;
                $key = key($table);
                $result[] = $table[$key];
            }
        }

        return $result;
    }

    /**
     * Get properties of table.
     *
     * @param string $database
     * @param string $table
     * @param string $guardedFields
     * @return array
     * @throws \Exception
     */
    protected function getTableProperties($database, $table, $guardedFields)
    {
        $primaryKey = $this->getTablePrimaryKey($database, $table);
        $primaryKey = $primaryKey != 'id' ? $primaryKey : null;

        $fillable = [];
        $guarded = [];
        $timestamps = false;

        $columns = $this->getTableColumns($database, $table);
        foreach ($columns as $column) {
            if (in_array($column->name, $guardedFields)) {
                $guarded[] = $column->name;
            } else {
                $fillable[] = $column->name;
            }
        }

        return [
            'primaryKey' => $primaryKey,
            'fillable' => $fillable,
            'guarded' => $guarded,
            'timestamps' => $timestamps,
        ];
    }

    /**
     * Get primary key from table.
     *
     * @param string $database
     * @param string $table
     * @return string
     * @throws \Exception
     */
    private function getTablePrimaryKey($database, $table)
    {
        $driver = $this->getDatabaseDriver($database);
        switch ($driver) {
            case 'mysql':
                $sql = "SELECT COLUMN_NAME";
                $sql .= " FROM information_schema.COLUMNS";
                $sql .= " WHERE TABLE_SCHEMA = DATABASE()";
                $sql .= " AND COLUMN_KEY = 'PRI'";
                $sql .= " AND TABLE_NAME = '" . $table . "'";
                $primaryKeyResult = \DB::connection($database)->select($sql);
                break;

            case 'sqlsrv':
                throw new \Exception('Not implemented yet.');
                break;

            case 'sqlite':
                throw new \Exception('Not implemented yet.');
                break;

            case 'postgres':
                throw new \Exception('Not implemented yet.');
                break;

            default:
                throw new \Exception('Connection-driver [' . $driver . '] not supported.');
                break;
        }

        if (count($primaryKeyResult) == 1) {
            return $primaryKeyResult[0]->COLUMN_NAME;
        }

        return null;
    }

    /**
     * Get columns of table.
     *
     * @param string $database
     * @param string $table
     * @return mixed
     * @throws \Exception
     */
    protected function getTableColumns($database, $table)
    {
        $driver = $this->getDatabaseDriver($database);
        switch ($driver) {
            case 'mysql':
                $sql = "SELECT COLUMN_NAME AS `name`";
                $sql .= ", DATA_TYPE AS `type`";
                $sql .= ", COLUMN_TYPE AS `column_type`";
                $sql .= ", IS_NULLABLE AS `is_nullable`";
                $sql .= ", COLUMN_DEFAULT AS `default`";
                $sql .= " FROM INFORMATION_SCHEMA.COLUMNS";
                $sql .= " WHERE TABLE_SCHEMA = DATABASE()";
                $sql .= " AND TABLE_NAME = '" . $table . "'";
                $columns = \DB::connection($database)->select($sql);
                break;

            case 'sqlsrv':
                throw new \Exception('Not implemented yet.');
                break;

            case 'sqlite':
                throw new \Exception('Not implemented yet.');
                break;

            case 'postgres':
                throw new \Exception('Not implemented yet.');
                break;

            default:
                throw new \Exception('Connection-driver [' . $driver . '] not supported.');
                break;
        }

        return $columns;
    }

    /**
     * Get driver of database.
     *
     * @param string $database
     * @return string
     * @throws \Exception
     */
    private function getDatabaseDriver($database)
    {
        $connection = config('database.connections.' . $database);
        if ($connection === null) {
            throw new \Exception('Database [' . $database . '] not found.');
        }
        return $connection['driver'];
    }

    /**
     * Convert array to line-separated strings.
     *
     * @param array $array
     * @return string
     */
    private function convertArrayToString(array $array)
    {
        $string = '[';
        if (!empty($array)) {
            $string .= "\n" . $this->indent . $this->indent . "'";
            $string .= implode("',\n" . $this->indent . $this->indent . "'", $array);
            $string .= "'\n" . $this->indent;
        }
        $string .= ']';

        return $string;
    }

    /**
     * Get doc properties.
     *
     * @param string $database
     * @param string $table
     * @param array $fillable
     * @return array
     * @throws \Exception
     */
    private function getDocProperties($database, $table, array $fillable)
    {
        $properties = [];
        $columns = $this->getTableColumns($database, $table);
        foreach ($columns as $column) {
            if (in_array($column->name, $fillable)) {
                $name = $column->name;

                // Convert types.
                $type = $column->type;
                $type = $type == 'varchar' ? 'string' : $type;
                $type = $type == 'longblob' ? 'string' : $type;
                $type = $type == 'longtext' ? 'string' : $type;
                $type = $type == 'datetime' ? 'string' : $type;
                $type = $type == 'date' ? 'string' : $type;
                $type = $type == 'text' ? 'string' : $type;
                $type = $type == 'tinyint' ? 'int' : $type;
                $type = $type == 'bigint' ? 'int' : $type;
                $type = $type == 'smallint' ? 'int' : $type;
                $type = $type == 'timestamp' ? 'int' : $type;

                $properties[] = ' * @property ' . $type . ' ' . $name . '. [' . $this->getAttributes($column) . ']';
            }
        }
        return $properties;
    }

    /**
     * Get preserved lines.
     *
     * @param string $filename
     * @return string
     * @throws \Exception
     */
    private function getPreservedLines($filename)
    {
        if (!file_exists($filename)) {
            return [];
        }
        $lines = explode("\n", file_get_contents($filename));
        $preservedLines = [];
        $found = false;
        foreach ($lines as $line) {
            if ($line == '}') {
                continue;
            }
            if ($found) {
                $preservedLines[] = $line;
            }
            if (is_int(strpos($line, '/* ----')) && is_int(strpos($line, '---- */'))) {
                $found = true;
            }
        }
        return $preservedLines;
    }

    /**
     * Build class-name.
     *
     * @param string $table
     * @return mixed
     */
    private function buildClassName($table)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));
    }

    /**
     * Build namespace.
     *
     * @param string $database
     * @return mixed|string
     */
    private function buildNamespace($database)
    {
        $namespace = config('corex.laravel-model-generator.namespace');
        $namespace .= '\\' . ucfirst($database);
        return $namespace;
    }

    /**
     * Build filename for model.
     *
     * @param string $database
     * @param string $table
     * @return mixed|string
     */
    private function buildFilename($database, $table)
    {
        $databaseSubDirectory = config('corex.laravel-model-generator.databaseSubDirectory');
        $path = config('corex.laravel-model-generator.path');
        if ($databaseSubDirectory) {
            $path .= '/' . Convention::getPascalCase($database);
        }
        $path .= '/' . $this->buildClassName($table);
        $path .= '.php';
        return $path;
    }

    /**
     * Get attributes for a column.
     *
     * @param object $column
     * @return string
     */
    private function getAttributes($column)
    {
	    d($column);
        $attributes = 'TYPE=' . strtoupper($column->column_type);
        $attributes .= ', NULLABLE=' . intval($column->column_type == 'YES');
        $attributes .= ', DEFAULT="' . $column->default . '"';
        return $attributes;
    }

    /**
     * Get "extends".
     *
     * @return string
     */
    private function getExtend()
    {
        $extends = config('corex.laravel-model-generator.extends');
        if ($extends !== null && trim($extends) != '') {
            return $extends;
        }
        return $this->extends;
    }
}
