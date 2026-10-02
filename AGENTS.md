# AGENTS.md

Contexto para agentes que trabajan en este repositorio. No es documentacion
general de Laravel: aqui esta lo que **no se deduce leyendo el codigo**, es decir
las decisiones y sus motivos.

## Que es esto

Sistema de fidelizacion multi-tenant para comercios de barrio ( cafes, peluquerias,
talleres ). Un cajero escanea la tarjeta de un cliente, cobra, y el cliente acumula
puntos que luego canjea por premios.

Tres piezas:

- **Ledger contable.** `point_ledger` es la fuente de verdad del saldo.
  `customers.current_points` es un saldo materializado que existe para no sumar en
  cada lectura.
- **Aislamiento por tenant.** RLS de PostgreSQL, no filtrado en PHP.
- **API para el POS.** El cajero opera contra endpoints HTTP, no contra la base.

## Reglas que se rompen con facilidad

### El saldo se calcula con bcmath, nunca con float

Dinero y puntos se mueven en **enteros de la unidad minima** (`amount_minor`, en
centavos). El reparto de puntos usa `bcmath` porque `0.1 + 0.2 !== 0.3` en punto
flotante, y aqui esa diferencia se convierte en puntos que un cliente reclama.

El redondeo ocurre **una sola vez**, al final, y es configurable por tenant
(`down`, `half_up`, `up`). Redondear a mitad de camino rompe la cuadra entre la
cabecera y el asiento.

### Toda operacion que mueve puntos es una transaccion con bloqueo de fila

`earn()` y `redeem()` usan `DB::transaction()` + `lockForUpdate()` sobre el
cliente. El orden de bloqueo es **siempre cliente, luego premio**, en todas las
rutas, para que dos cajeros no se bloqueen en cruz.

Un `redeem()` sin bloquear la fila del premio sobrevende: dos cajeros leen
`inventory = 1`, los dos pasan el control y queda `-1`. Por eso el UPDATE de stock
lleva ademas su propio `where('inventory', '>', 0)`.

### La idempotencia se comprueba al principio, no al final

Cada operacion acepta una clave y consulta si ya existe **antes** de escribir nada.
En HTTP llega en la cabecera `Idempotency-Key` y es **obligatoria**: sin ella, un
reintento del POS tras perder la red cobra dos veces.

La clave se normaliza (recortar + mayusculas) **antes** de validarse. Si se
normalizara despues, un `Idempotency-Key: POS-1 ` con espacio final no pasaria el
patron de caracteres admitidos y el POS no reconoceria su propio reintento.

Toda operacion tiene dos caminos de reintento, y ambos deben devolver el mismo
resultado: el chequeo previo y la `UniqueConstraintViolationException` que se
atrapa para cubrir la carrera entre dos peticiones concurrentes con la misma clave.

### La sesion de PostgreSQL debe estar en UTC

`config/database.php` fija `'timezone' => env('DB_TIMEZONE', '+00:00')`.

**No quitar esto sin replacement.** Laravel escribe las columnas `timestampTz`
sin offset. Si la sesion no esta en UTC, PostgreSQL interpreta el valor en la zona
del servidor y guarda cada instante **adelantado**. Paso: todo quedaba 3 horas en
futuro y `point_ledger.recorded_at` dejaba dealinearse con las ventanas del tope
diario. Esta cubierto por `test_las_marcas_de_tiempo_se_guardan_en_el_instante_real`.

### El dia del tope diario es el del cliente, no el del servidor

`max_points_per_day` se calcula sobre la zona horaria de `customers.timezone`. Un
cliente en UTC-6 a las 20:00 local ya es el dia siguiente en UTC; con un corte en
UTC perderia compras de la tarde y las contaria dos veces al dia siguiente.

La ventana se construye con `CarbonImmutable`. Con `Carbon` mutable, `startOfDay()`
modifica el objeto en vez de devolver una copia, y dos llamadas encadenadas dejan
la ventana como `[X, X)`, es decir **vacia**: el tope no se aplica nunca y no salta
ningun error. Ese fallo ya ocurrio.

### Los errores de negocio son excepciones, no `throw new RuntimeException`

Toda excepcion de dominio implementa `RendersApiError` y responde con
`errorCode()`, `errorStatus()` y `errorDetails()`. Los controladores no conocen los
casos: traducen con `fromDomainError()`.

Agregar un error nuevo no debe requerir tocar ningun controller. Un error de
inventario (`RewardUnavailable`) esta **separado** de uno de saldo
(`InsufficientPoints`) a proposito: decirle al cliente "te faltan puntos" de un
premio agotado lo hace juntar puntos para algo que ya no existe.

## Codigo y tests

- Tests en espanol, nombres que describen comportamiento:
  `test_un_canje_resta_puntos_y_deja_el_asiento_en_negativo`.
- Un test de contabilidad comprueba **las tres cosas a la vez**: cabecera, asiento y
  saldo materializado. Un test que solo mira el saldo del cliente pasaria con el
  ledger vacio.
- Los factories **nunca inventan saldos**. `CustomerFactory` deja `current_points`
  en 0; para tener saldo se usa `->withPoints(n)`. Fabricar un saldo directo
  produce estados imposibles y tests que pasan sin ejercitar el ledger.
- Los tests HTTP heredan de `AuthenticatedTestCase`, que resuelve el tenant por
  subdominio de verdad. Setear el contexto a mano probaria el controller y dejaria
  sin comprobar la frontera que protege los datos.

## Verificar antes de decir que algo funciona

```bash
php artisan test
./vendor/bin/pint --test
```

Los dos deben pasar. En `/mnt/c` (DrvFs) la suite tarda ~50 s en vez de ~5 s: es el
coste de cruzar la frontera de archivos de WSL, no el codigo. El primer request tras
arrancar `artisan serve` tambien tarda varios segundos por lo mismo.

Para probar de punta a punta sin HTTP:

```bash
php artisan loyalty:demo --fresh
```

Es repetible y determinista: reinicia clientes, inventario y contadores de la
corramada anterior.

## Pendientes conocidos

No estan implementados. No los trates como bugs:

- **Reversa de transacciones ya posteadas.** El esquema tiene
  `transactions.reverses_transaction_id`, pero no hay servicio que la use.
- **`idempotency_keys` sin usar.** La idempotencia vive en
  `transactions.idempotency_key`. La tabla quedo sin uso.
- **Busqueda de cliente por tarjeta.** `POST /pos/earn` y `/pos/redeem` exigen
  `customer_id`. Un POS real escanea una tarjeta; resolver el cliente desde el
  escaneo es trabajo pendiente.
- **CRUD de clientes, QR one-use y Wallet**, fuera de alcance por decision de
  producto.

## Notas del entorno

- PostgreSQL corre **dentro de WSL**, no en Docker. Vive fuera del repo, asi que
  mover o borrar el proyecto no la afecta.
- El proyecto esta en `/mnt/c`, que es disco de Windows. Para la velocidad de los
  tests, un checkout en el disco de Linux es ~10 veces mas rapido.