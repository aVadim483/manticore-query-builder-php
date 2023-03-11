# Manticore Search Query Builder for PHP (unofficial PHP client)

## ResultSet

### result(): mixed
Depends on last query:
* Collection of rows for SELECT query
* Boolean for CREATE, UPDATE and DROP queries
* Bigint for INSERT
* Array for others   

### command(): ?string
Returns a command ('SELECT', 'INSERT', 'SHOW TABLES', etc)

### sqlQuery(): ?string
Returns SQL query

### public function execTime(): ?float
Returns execution time

### success(): bool
Result without errors and warnings

### status(): ?string
The last result of query

## Specific methods for SELECT

### result(): array
Collection of rows

### columns(): array
Returns array of columns names

### count(): int
Returns count of result rows

### total(): int
Returns total number of rows that match the condition in table

### first(): array
Returns the first row of rows set

### meta(): array
Returns the meta data received after SQL request

### facets(): array
Returns facets

## Specific method for SHOW VARIABLES

### variable($name): mixed
Returns value of variable
