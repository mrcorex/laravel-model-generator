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
    protected $preserved = '/' . '* ---- Everything after this line will be preserved. ---- *' . '/';

    /**
     * Stub.
     *
     * @var
     */
    private $stub;

    /**
     * Standard characters to be replaced.
     *
     * @var array
     */
    private $standardReplace = [
        'Æ' => 'AE',
        'Ø' => 'OE',
        'Å' => 'AA'
    ];

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

        // Get indent from config-file and use if exist.
        $indent = config('corex.laravel-model-generator.indent');
        if ($indent) {
            $this->indent = $indent;
        }

        $connection = $this->argument('connection');
        $tables = $this->argument('tables');
        $guardedFields = $this->option('guarded');
        if ($guardedFields !== null && is_string($guardedFields) && $guardedFields != '') {
            $guardedFields = explode(',', $guardedFields);
        } else {
            $guardedFields = [];
        }
        $this->stub = file_get_contents($this->getStub());

        // Tables.
        \DB::connection($connection)->setFetchMode(\PDO::FETCH_ASSOC);
        if ($tables != '.') {
            $tables = explode(',', $tables);
        } else {
            $tables = $this->getTables($connection);
        }

        // Make models.
        if (count($tables) > 0) {
            foreach ($tables as $table) {
                $this->makeModel($connection, $table, $guardedFields);
            }
        }
    }

    /**
     * Make model.
     *
     * @param string $connection
     * @param string $table
     * @param array $guardedFields
     * @throws \Exception
     */
    protected function makeModel($connection, $table, array $guardedFields)
    {
        $filename = $this->buildFilename($connection, $table);
        $this->makeDirectory($filename);
        $preservedInformation = $this->getPreservedInformation($filename);
        $classContent = $this->replaceTokens(
            $connection,
            $table,
            $preservedInformation['lines'],
            $guardedFields,
            $preservedInformation['uses']
        );
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
     * @param string $connection
     * @param string $table
     * @param array $preservedLines
     * @param array $guardedFields
     * @param array $preservedUses
     * @return mixed|string
     */
    protected function replaceTokens($connection, $table, array $preservedLines, array $guardedFields, array $preservedUses)
    {
        $class = $this->buildClassName($table);
        $namespace = $this->buildNamespace($connection);
        $extends = $this->getExtend();
        $uses = $this->getUses($preservedUses);

        $stub = $this->stub;

        $properties = $this->getTableProperties($connection, $table, $guardedFields);
        if (count($properties['fillable']) == 0) {
            return '';
        }

        $constants = $this->getConstants($connection, $table);
        $stub = str_replace('{{constants}}', implode("\n", $constants), $stub);

        $stub = str_replace('{{namespace}}', $namespace, $stub);

        $stub = str_replace('{{uses}}', $uses, $stub);

        $stub = str_replace('{{class}}', $class, $stub);

        $docProperties = $this->getDocProperties($connection, $table, $properties['fillable']);
        $stub = str_replace('{{properties}}', implode("\n", $docProperties), $stub);

        $classParts = explode('\\', $extends);
        $model = end($classParts);
        $stub = str_replace('{{shortNameExtends}}', $model, $stub);

        $stub = str_replace(
            '{{connection}}',
            $this->indent . 'protected $connection = \'' . $connection . '\';' . "\n\n",
            $stub
        );

        $stub = str_replace(
            '{{table}}',
            $this->indent . 'protected $table = \'' . $table . '\';' . "\n\n",
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
            $stub = str_replace(
                $this->preserved,
                $this->indent . $this->preserved . "\n" . implode("\n", $preservedLines),
                $stub
            );
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
            ['connection', InputArgument::REQUIRED, 'Name of connection.'],
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
     * @param string $connection
     * @return mixed
     * @throws \Exception
     */
    private function getTables($connection)
    {
        $result = [];
        $driver = $this->getConnectionProperty($connection, 'driver');
        switch ($driver) {
            case 'mysql':
                $tables = \DB::connection($connection)->select("SHOW TABLES");
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
     * @param string $connection
     * @param string $table
     * @param array $guardedFields
     * @return array
     * @throws \Exception
     */
    protected function getTableProperties($connection, $table, array $guardedFields)
    {
        $primaryKey = $this->getTablePrimaryKey($connection, $table);
        $primaryKey = $primaryKey != 'id' ? $primaryKey : null;

        $fillable = [];
        $guarded = [];
        $timestamps = false;

        $columns = $this->getTableColumns($connection, $table);
        foreach ($columns as $column) {
            if (in_array($column['name'], $guardedFields)) {
                $guarded[] = $column['name'];
            } else {
                $fillable[] = $column['name'];
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
     * @param string $connection
     * @param string $table
     * @return string
     * @throws \Exception
     */
    private function getTablePrimaryKey($connection, $table)
    {
        $driver = $this->getConnectionProperty($connection, 'driver');
        switch ($driver) {
            case 'mysql':
                $sql = "SELECT COLUMN_NAME";
                $sql .= " FROM information_schema.COLUMNS";
                $sql .= " WHERE TABLE_SCHEMA = DATABASE()";
                $sql .= " AND COLUMN_KEY = 'PRI'";
                $sql .= " AND TABLE_NAME = '" . $table . "'";
                $primaryKeyResult = \DB::connection($connection)->select($sql);
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
            return $primaryKeyResult[0]['COLUMN_NAME'];
        }

        return null;
    }

    /**
     * Get columns of table.
     *
     * @param string $connection
     * @param string $table
     * @return mixed
     * @throws \Exception
     */
    protected function getTableColumns($connection, $table)
    {
        $driver = $this->getConnectionProperty($connection, 'driver');
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
                $columns = \DB::connection($connection)->select($sql);
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
     * @param string $connection
     * @param string $property
     * @return string
     * @throws \Exception
     */
    private function getConnectionProperty($connection, $property)
    {
        $connection = config('database.connections.' . $connection);
        if ($connection === null) {
            throw new \Exception('Connection [' . $connection . '] not found.');
        }
        return isset($connection[$property]) ? $connection[$property] : null;
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
     * @param string $connection
     * @param string $table
     * @param array $fillable
     * @return array
     * @throws \Exception
     */
    private function getDocProperties($connection, $table, array $fillable)
    {
        $properties = [];
        $columns = $this->getTableColumns($connection, $table);
        foreach ($columns as $column) {
            if (in_array($column['name'], $fillable)) {
                $name = $column['name'];

                // Convert types.
                $type = $column['type'];
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
     * Get preserved information.
     *
     * @param string $filename
     * @return array
     * @throws \Exception
     */
    private function getPreservedInformation($filename)
    {
        if (!file_exists($filename)) {
            return [
                'lines' => [],
                'uses' => []
            ];
        }
        $lines = explode("\n", file_get_contents($filename));
        $preservedLines = [];
        $preservedUses = [];
        $found = false;
        foreach ($lines as $line) {
            if ($line == '}') {
                continue;
            }
            if (stripos($line, "use") === 0) {
                $preservedUses[] = str_replace(["use", " ", ";"], "", $line);
            }
            if ($found) {
                $preservedLines[] = $line;
            }
            if (is_int(strpos($line, '/* ----')) && is_int(strpos($line, '---- */'))) {
                $found = true;
            }
        }
        return [
            'lines' => $preservedLines,
            'uses' => $preservedUses
        ];
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
        $attributes = 'TYPE=' . strtoupper($column['column_type']);
        $attributes .= ', NULLABLE=' . intval($column['column_type'] == 'YES');
        $attributes .= ', DEFAULT="' . $column['default'] . '"';
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

    /**
     * Get "uses".
     *
     * @param array $preservedUses
     * @return array
     * @throws \Exception
     */
    private function getUses(array $preservedUses)
    {
        $uses = config('corex.laravel-model-generator.uses') ?: [];
        if (is_array($uses) !== true) {
            throw new \Exception("Uses setting must be an array.");
        }
        $extends = $this->getExtend();
        if ($extends !== "") {
            $uses[] = $extends;
        }
        if (count($preservedUses) > 0) {
            $uses = array_merge($uses, $preservedUses);
        }
        $uniqueUses = array_unique($uses);
        $completeUses = array_map(function ($use) {
            return "use " . $use . ";";
        }, $uniqueUses);
        return implode("\n", $completeUses);
    }

    /**
     * Get constants.
     *
     * @param string $connection
     * @param string $table
     * @return array
     * @throws \Exception
     */
    private function getConstants($connection, $table)
    {
        $constSettings = $this->getConstSettings($connection, $table);
        $constants = [];
        if ($constSettings !== null) {

            // Extract name of fields.
            if (!isset($constSettings['id'])) {
                throw new \Exception('Field "id" not set.');
            }
            if (!isset($constSettings['name'])) {
                throw new \Exception('Field "name" not set.');
            }
            $idField = $constSettings['id'];
            $nameField = $constSettings['name'];
            $prefix = isset($constSettings['prefix']) ? (string)$constSettings['prefix'] : '';
            $suffix = isset($constSettings['suffix']) ? (string)$constSettings['suffix'] : '';
            $replace = isset($constSettings['replace']) ? $constSettings['replace'] : [];

            // Get data.
            $query = \DB::connection($connection)->table($table);
            if ($idField != '') {
                $query->orderBy($idField);
            }
            $rows = $query->get();
            if (count($rows) == 0) {
                return [];
            }

            // Determine if value is string.
            $quotes = $this->ifStringInRows($rows->toArray(), $idField);

            // Check if fields exists in rows.
            if (!isset($rows[0][$idField])) {
                throw new \Exception('Field "' . $idField . '" does not exist in data.');
            }
            if (!isset($rows[0][$nameField])) {
                throw new \Exception('Field "' . $nameField . '" does not exist in data.');
            }

            // Find constants.
            $constantArray = [];
            if (count($rows) > 0) {
                foreach ($rows as $row) {
                    $constant = mb_strtoupper($row[$nameField]);
                    $constant = $this->replaceCharacters($constant, $replace);
                    $constant = $prefix . $constant . $suffix;
                    $value = $row[$idField];
                    if ($quotes) {
                        $value = '\'' . $value . '\'';
                    }
                    $constantArray[$constant] = $value;
                }
            }

            // Build constants string.
            $constants[] = $this->indent . '// Constants.';
            foreach ($constantArray as $name => $value) {
                $constants[] = $this->indent . 'const ' . $name . ' = ' . $value . ';';
            }
            $constants[] = '';
            $constants[] = '';
        }
        return $constants;
    }

    /**
     * Replace characters.
     *
     * @param string $data
     * @param array $replace
     * @return mixed
     */
    private function replaceCharacters($data, array $replace)
    {
        $data = mb_strtoupper($data);
        $data = str_replace(
            ['-', '.', ',', ';', ':', ' ', '?', '\'', '"', '#', '%', '&', '/', '\\', '(', ')'],
            '_',
            $data
        );
        $replace = array_merge($this->standardReplace, $replace);
        foreach ($replace as $from => $to) {
            $data = str_replace(mb_strtoupper($from), mb_strtoupper($to), $data);
        }
        return $data;
    }

    /**
     * Get const settings.
     *
     * @param string $connection
     * @param string $table
     * @return mixed
     */
    private function getConstSettings($connection, $table)
    {
        return config('corex.laravel-model-generator.const.' . $connection . '.' . $table);
    }

    /**
     * Determine if key in rows is strings.
     *
     * @param array $rows
     * @param string $key
     * @return boolean
     */
    private function ifStringInRows(array $rows, $key)
    {
        $stringInRows = false;
        if (count($rows) == 0) {
            return $stringInRows;
        }
        foreach ($rows as $row) {
            if (isset($row[$key]) && !is_numeric($row[$key])) {
                $stringInRows = true;
            }
        }
        return $stringInRows;
    }
}
