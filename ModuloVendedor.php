<?php

session_start();

require_once "../conexion.php";


/* =========================================================
   PROTEGER MÓDULO
========================================================= */

if (
    !isset($_SESSION["id_usuario"]) ||
    !isset($_SESSION["rol"]) ||
    $_SESSION["rol"] !== "vendedor"
) {

    header("Location: ../index.php");
    exit;
}


/* =========================================================
   DATOS DEL VENDEDOR
========================================================= */

$idVendedor =
    (int) $_SESSION["id_usuario"];

$nombreVendedor =
    $_SESSION["nombre"] ?? "Vendedor";


$inicialVendedor =
    strtoupper(
        substr(
            trim($nombreVendedor),
            0,
            1
        )
    );


/* =========================================================
   VARIABLES
========================================================= */

$mensajeError = "";
$mensajeExito = "";

$ventaSeleccionada = null;


/* =========================================================
   FUNCIÓN PREPARAR CONSULTA
========================================================= */

function prepararConsulta($conn, $sql)
{
    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {

        throw new Exception(
            "Error SQL: " .
            $conn->error
        );
    }

    return $stmt;
}


/* =========================================================
   ID SELECCIONADO
========================================================= */

$idSeleccionado =
    (int) (
        $_GET["venta"]
        ??
        $_POST["venta_id"]
        ??
        0
    );


/* =========================================================
   REGISTRAR PAGO
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    $ventaId =
        (int) (
            $_POST["venta_id"]
            ?? 0
        );


    $monto =
        (float) (
            $_POST["monto"]
            ?? 0
        );


    $metodoPago =
        trim(
            $_POST["metodo_pago"]
            ?? ""
        );


    $referenciaPago =
        trim(
            $_POST["referencia_pago"]
            ?? ""
        );


    /* =====================================================
       VALIDACIONES
    ===================================================== */

    if ($ventaId <= 0) {

        $mensajeError =
            "Selecciona una cuenta pendiente.";

    }

    elseif ($monto <= 0) {

        $mensajeError =
            "Ingresa un valor válido para el pago.";

    }

    elseif (
        !in_array(
            $metodoPago,
            [
                "efectivo",
                "qr"
            ],
            true
        )
    ) {

        $mensajeError =
            "Selecciona un método de pago válido.";

    }

    elseif (
        $metodoPago === "qr" &&
        $referenciaPago === ""
    ) {

        $mensajeError =
            "Ingresa el número de referencia del pago QR.";

    }

    else {


        $conn->begin_transaction();


        try {


            /* =================================================
               CONSULTAR VENTA A CRÉDITO
            ================================================= */

            $sqlVenta = "
                SELECT
                    v.id,
                    v.cliente_id,
                    v.total,
                    v.estado_pago,
                    u.nombre_completo

                FROM ventas v

                INNER JOIN usuarios u
                    ON u.id = v.cliente_id

                WHERE v.id = ?

                AND v.metodo_pago = 'credito'

                FOR UPDATE
            ";


            $stmtVenta =
                prepararConsulta(
                    $conn,
                    $sqlVenta
                );


            $stmtVenta->bind_param(
                "i",
                $ventaId
            );


            $stmtVenta->execute();


            $resultadoVenta =
                $stmtVenta->get_result();


            if (
                $resultadoVenta->num_rows !== 1
            ) {

                throw new Exception(
                    "La cuenta seleccionada no existe."
                );
            }


            $venta =
                $resultadoVenta->fetch_assoc();


            $stmtVenta->close();


            /* =================================================
               CALCULAR ABONOS
            ================================================= */

            $sqlAbonos = "
                SELECT
                    COALESCE(
                        SUM(monto),
                        0
                    ) AS total_abonado

                FROM abonos_credito

                WHERE venta_id = ?
            ";


            $stmtAbonos =
                prepararConsulta(
                    $conn,
                    $sqlAbonos
                );


            $stmtAbonos->bind_param(
                "i",
                $ventaId
            );


            $stmtAbonos->execute();


            $resultadoAbonos =
                $stmtAbonos->get_result();


            $filaAbonos =
                $resultadoAbonos->fetch_assoc();


            $totalAbonado =
                (float) (
                    $filaAbonos["total_abonado"]
                    ?? 0
                );


            $stmtAbonos->close();


            /* =================================================
               SALDO ACTUAL
            ================================================= */

            $saldoActual =
                (float) $venta["total"]
                -
                $totalAbonado;


            if ($saldoActual <= 0) {

                throw new Exception(
                    "Esta cuenta ya está completamente pagada."
                );
            }


            if ($monto > $saldoActual) {

                throw new Exception(
                    "El pago no puede superar el saldo pendiente de $" .
                    number_format(
                        $saldoActual,
                        0,
                        ",",
                        "."
                    ) .
                    "."
                );
            }


            /* =================================================
               REFERENCIA
            ================================================= */

            $referenciaReal = null;


            if ($metodoPago === "qr") {

                $referenciaReal =
                    $referenciaPago;
            }


            /* =================================================
               REGISTRAR ABONO
            ================================================= */

            $sqlRegistrarAbono = "
                INSERT INTO abonos_credito
                (
                    venta_id,
                    monto,
                    metodo_pago,
                    referencia_pago,
                    registrado_por
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ";


            $stmtRegistrar =
                prepararConsulta(
                    $conn,
                    $sqlRegistrarAbono
                );


            $stmtRegistrar->bind_param(
                "idssi",
                $ventaId,
                $monto,
                $metodoPago,
                $referenciaReal,
                $idVendedor
            );


            if (
                !$stmtRegistrar->execute()
            ) {

                throw new Exception(
                    "No fue posible registrar el pago."
                );
            }


            $stmtRegistrar->close();


            /* =================================================
               CALCULAR NUEVO SALDO
            ================================================= */

            $nuevoSaldo =
                $saldoActual -
                $monto;


            if ($nuevoSaldo <= 0) {

                $nuevoSaldo = 0;

                $nuevoEstado =
                    "pagado";

            } else {

                $nuevoEstado =
                    "parcial";
            }


            /* =================================================
               ACTUALIZAR ESTADO DE LA VENTA
            ================================================= */

            $sqlActualizarVenta = "
                UPDATE ventas

                SET estado_pago = ?

                WHERE id = ?
            ";


            $stmtActualizar =
                prepararConsulta(
                    $conn,
                    $sqlActualizarVenta
                );


            $stmtActualizar->bind_param(
                "si",
                $nuevoEstado,
                $ventaId
            );


            if (
                !$stmtActualizar->execute()
            ) {

                throw new Exception(
                    "No fue posible actualizar el estado de la cuenta."
                );
            }


            $stmtActualizar->close();


            /* =================================================
               CONFIRMAR
            ================================================= */

            $conn->commit();


            $mensajeExito =
                "Pago registrado correctamente. Saldo pendiente: $" .
                number_format(
                    $nuevoSaldo,
                    0,
                    ",",
                    "."
                ) .
                ".";


            /*
             * Si quedó completamente pagada,
             * quitamos la selección.
             */

            if ($nuevoSaldo <= 0) {

                $idSeleccionado = 0;

            } else {

                $idSeleccionado =
                    $ventaId;
            }


        } catch (Throwable $e) {


            $conn->rollback();


            $mensajeError =
                $e->getMessage();

        }

    }

}


/* =========================================================
   CONSULTAR CUENTAS PENDIENTES
========================================================= */

$cuentasPendientes = [];


$sqlCuentas = "
    SELECT

        v.id,
        v.cliente_id,
        v.total,
        v.estado_pago,
        v.fecha,

        u.nombre_completo,
        u.documento,
        u.rol,
        u.grado,

        COALESCE(
            SUM(a.monto),
            0
        ) AS total_abonado

    FROM ventas v

    INNER JOIN usuarios u
        ON u.id = v.cliente_id

    LEFT JOIN abonos_credito a
        ON a.venta_id = v.id

    WHERE
        v.metodo_pago = 'credito'

    AND
        v.estado_pago IN (
            'pendiente',
            'parcial'
        )

    GROUP BY
        v.id,
        v.cliente_id,
        v.total,
        v.estado_pago,
        v.fecha,
        u.nombre_completo,
        u.documento,
        u.rol,
        u.grado

    HAVING
        (
            v.total
            -
            COALESCE(
                SUM(a.monto),
                0
            )
        ) > 0

    ORDER BY
        v.fecha ASC
";


$resultadoCuentas =
    $conn->query(
        $sqlCuentas
    );


if ($resultadoCuentas) {


    while (
        $fila =
        $resultadoCuentas->fetch_assoc()
    ) {


        $fila["saldo"] =
            (float) $fila["total"]
            -
            (float) $fila["total_abonado"];


        $cuentasPendientes[] =
            $fila;

    }


} else {


    $mensajeError =
        "No fue posible consultar las cuentas pendientes: " .
        $conn->error;

}


/* =========================================================
   BUSCAR CUENTA SELECCIONADA
========================================================= */

foreach (
    $cuentasPendientes
    as
    $cuenta
) {


    if (
        (int) $cuenta["id"] ===
        $idSeleccionado
    ) {


        $ventaSeleccionada =
            $cuenta;

        break;

    }

}


/* =========================================================
   FUNCIÓN PARA MOSTRAR ROL
========================================================= */

function mostrarRol($cuenta)
{

    if (
        $cuenta["rol"] ===
        "estudiante"
    ) {


        $texto =
            "Estudiante";


        if (
            !empty(
                $cuenta["grado"]
            )
        ) {

            $texto .=
                " · Grado " .
                $cuenta["grado"] .
                "°";
        }


        return $texto;

    }


    if (
        $cuenta["rol"] ===
        "profesor"
    ) {

        return "Profesor";
    }


    if (
        $cuenta["rol"] ===
        "administrativo"
    ) {

        return "Planta administrativa";
    }


    return ucfirst(
        $cuenta["rol"]
    );
}

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Registrar pago | Cafetería Inteligente
    </title>


    <link
        rel="stylesheet"
        href="ModuloVendedor.css?v=<?php echo time(); ?>"
    >


    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

</head>


<body>


<div class="app">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar">


        <!-- LOGO -->

        <div class="sidebar-brand">


            <img
                src="https://www.colegioliceomoderno.edu.co/img/logoclm500px.png"
                alt="Colegio Liceo Moderno"
            >


            <div>

                <strong>
                    Cafetería
                </strong>

                <span>
                    Inteligente
                </span>

            </div>


        </div>



        <!-- =================================================
             MENÚ
        ================================================== -->

        <nav class="sidebar-menu">


            <a href="inicio.php">

                <i class="fa-solid fa-house"></i>

                Inicio

            </a>


            <a href="pedidos.php">

                <i class="fa-solid fa-receipt"></i>

                Pedidos

            </a>


            <a href="nueva_venta.php">

                <i class="fa-solid fa-cart-plus"></i>

                Nueva venta

            </a>


            <a href="productos.php">

                <i class="fa-solid fa-box"></i>

                Productos

            </a>


            <a href="inventario.php">

                <i class="fa-solid fa-boxes-stacked"></i>

                Inventario

            </a>


            <a
                href="creditos.php"
                class="active"
            >

                <i class="fa-solid fa-hand-holding-dollar"></i>

                Cuentas por cobrar

            </a>


            <a href="ventas.php">

                <i class="fa-solid fa-clock-rotate-left"></i>

                Historial

            </a>


        </nav>



        <!-- =================================================
             PERFIL
        ================================================== -->

        <a
            href="perfil.php"
            class="sidebar-user"
        >


            <div class="user-avatar">

                <?php

                echo htmlspecialchars(
                    $inicialVendedor,
                    ENT_QUOTES,
                    "UTF-8"
                );

                ?>

            </div>


            <div>


                <strong>

                    <?php

                    echo htmlspecialchars(
                        $nombreVendedor,
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    ?>

                </strong>


                <span>
                    Vendedor
                </span>


            </div>


        </a>



        <!-- =================================================
             CERRAR SESIÓN
        ================================================== -->

        <a
            href="../cerrar_sesion.php"
            class="logout"
        >

            <i class="fa-solid fa-right-from-bracket"></i>

            Cerrar sesión

        </a>


    </aside>



    <!-- =====================================================
         CONTENIDO
    ====================================================== -->

    <main class="main-content">


        <a
            href="inicio.php"
            class="back-link"
        >

            <i class="fa-solid fa-arrow-left"></i>

            Volver al inicio

        </a>



        <!-- =================================================
             HEADER
        ================================================== -->

        <header class="page-header">


            <h1>
                Registrar pago
            </h1>


            <p>
                Registra un abono o el pago total de una cuenta pendiente.
            </p>


        </header>



        <!-- =================================================
             MENSAJES
        ================================================== -->

        <?php if ($mensajeError !== ""): ?>


            <div class="alert alert-error">


                <i class="fa-solid fa-circle-exclamation"></i>


                <span>

                    <?php

                    echo htmlspecialchars(
                        $mensajeError,
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    ?>

                </span>


            </div>


        <?php endif; ?>



        <?php if ($mensajeExito !== ""): ?>


            <div class="alert alert-success">


                <i class="fa-solid fa-circle-check"></i>


                <span>

                    <?php

                    echo htmlspecialchars(
                        $mensajeExito,
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    ?>

                </span>


            </div>


        <?php endif; ?>



        <!-- =================================================
             DOS COLUMNAS
        ================================================== -->

        <div class="payment-container">


            <!-- =============================================
                 CUENTAS PENDIENTES
            ============================================== -->

            <section class="card">


                <div class="card-header">


                    <h2>
                        Cuentas pendientes
                    </h2>


                    <p>
                        Selecciona la persona que realizará el pago.
                    </p>


                </div>



                <div class="card-body">


                    <!-- BUSCADOR -->

                    <div class="search-box">


                        <i class="fa-solid fa-magnifying-glass"></i>


                        <input
                            type="text"
                            id="buscarCliente"
                            placeholder="Buscar por nombre o documento"
                        >


                    </div>



                    <?php if (
                        count($cuentasPendientes) > 0
                    ): ?>


                        <div
                            class="pending-list"
                            id="listaCreditos"
                        >


                            <?php foreach (
                                $cuentasPendientes
                                as
                                $cuenta
                            ): ?>


                                <div
                                    class="pending-item <?php

                                    echo (
                                        (int) $cuenta["id"]
                                        ===
                                        $idSeleccionado
                                    )
                                        ? "selected"
                                        : "";

                                    ?>"

                                    data-nombre="<?php

                                    echo htmlspecialchars(
                                        strtolower(
                                            $cuenta[
                                                "nombre_completo"
                                            ]
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>"

                                    data-documento="<?php

                                    echo htmlspecialchars(
                                        $cuenta["documento"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>"

                                    onclick="seleccionarVenta(
                                        <?php
                                        echo (int) $cuenta["id"];
                                        ?>
                                    )"
                                >


                                    <div class="pending-info">


                                        <strong>

                                            <?php

                                            echo htmlspecialchars(
                                                $cuenta[
                                                    "nombre_completo"
                                                ],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            );

                                            ?>

                                        </strong>


                                        <span>

                                            Documento:
                                            <?php

                                            echo htmlspecialchars(
                                                $cuenta[
                                                    "documento"
                                                ],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            );

                                            ?>

                                        </span>


                                        <span>

                                            <?php

                                            echo htmlspecialchars(
                                                mostrarRol(
                                                    $cuenta
                                                ),
                                                ENT_QUOTES,
                                                "UTF-8"
                                            );

                                            ?>

                                        </span>


                                        <span>

                                            Cuenta #
                                            <?php
                                            echo (int) $cuenta["id"];
                                            ?>

                                        </span>


                                    </div>



                                    <div class="pending-amount">


                                        $

                                        <?php

                                        echo number_format(
                                            $cuenta["saldo"],
                                            0,
                                            ",",
                                            "."
                                        );

                                        ?>


                                    </div>


                                </div>


                            <?php endforeach; ?>


                        </div>


                    <?php else: ?>


                        <div class="empty-state">


                            <i class="fa-solid fa-circle-check"></i>


                            <strong>
                                No hay cuentas pendientes
                            </strong>


                            <p>
                                Cuando registres una venta a crédito,
                                aparecerá aquí para recibir pagos o abonos.
                            </p>


                        </div>


                    <?php endif; ?>


                </div>


            </section>



            <!-- =============================================
                 DETALLE DEL PAGO
            ============================================== -->

            <section class="card">


                <div class="card-header">


                    <h2>
                        Detalle del pago
                    </h2>


                    <p>
                        Revisa la información antes de registrar el abono.
                    </p>


                </div>



                <div class="card-body">


                    <?php if (
                        $ventaSeleccionada
                    ): ?>


                        <!-- CLIENTE -->

                        <div class="client-info">


                            <div class="client-info-row">


                                <span>
                                    Cliente
                                </span>


                                <strong>

                                    <?php

                                    echo htmlspecialchars(
                                        $ventaSeleccionada[
                                            "nombre_completo"
                                        ],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="client-info-row">


                                <span>
                                    Documento
                                </span>


                                <strong>

                                    <?php

                                    echo htmlspecialchars(
                                        $ventaSeleccionada[
                                            "documento"
                                        ],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="client-info-row">


                                <span>
                                    Tipo
                                </span>


                                <strong>

                                    <?php

                                    echo htmlspecialchars(
                                        mostrarRol(
                                            $ventaSeleccionada
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                </strong>


                            </div>


                        </div>



                        <!-- =================================================
                             DEUDA
                        ================================================== -->

                        <div class="debt-summary">


                            <div class="debt-row">


                                <span>
                                    Valor inicial
                                </span>


                                <strong>

                                    $

                                    <?php

                                    echo number_format(
                                        $ventaSeleccionada[
                                            "total"
                                        ],
                                        0,
                                        ",",
                                        "."
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="debt-row">


                                <span>
                                    Total abonado
                                </span>


                                <strong>

                                    $

                                    <?php

                                    echo number_format(
                                        $ventaSeleccionada[
                                            "total_abonado"
                                        ],
                                        0,
                                        ",",
                                        "."
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="debt-row total">


                                <span>
                                    Saldo pendiente
                                </span>


                                <strong>

                                    $

                                    <?php

                                    echo number_format(
                                        $ventaSeleccionada[
                                            "saldo"
                                        ],
                                        0,
                                        ",",
                                        "."
                                    );

                                    ?>

                                </strong>


                            </div>


                        </div>



                        <!-- =================================================
                             FORMULARIO
                        ================================================== -->

                        <form
                            method="POST"
                            class="payment-form"
                        >


                            <input
                                type="hidden"
                                name="venta_id"

                                value="<?php
                                echo (int)
                                    $ventaSeleccionada["id"];
                                ?>"
                            >



                            <!-- VALOR -->

                            <div class="form-group">


                                <label for="monto">

                                    Valor del pago *

                                </label>


                                <div class="money-input">


                                    <span>
                                        $
                                    </span>


                                    <input
                                        type="number"
                                        id="monto"
                                        name="monto"

                                        class="form-control"

                                        min="1"

                                        max="<?php
                                        echo (float)
                                            $ventaSeleccionada[
                                                "saldo"
                                            ];
                                        ?>"

                                        step="1"

                                        placeholder="0"

                                        required
                                    >


                                </div>


                            </div>



                            <!-- =================================================
                                 MÉTODO
                            ================================================== -->

                            <div class="form-group">


                                <label>
                                    Método de pago *
                                </label>


                                <input
                                    type="hidden"
                                    id="metodoPago"
                                    name="metodo_pago"
                                    value="efectivo"
                                >


                                <div class="payment-methods">


                                    <div
                                        class="payment-method active"
                                        data-metodo="efectivo"
                                    >


                                        <i class="fa-solid fa-money-bill-wave"></i>


                                        <span>
                                            Efectivo
                                        </span>


                                    </div>



                                    <div
                                        class="payment-method"
                                        data-metodo="qr"
                                    >


                                        <i class="fa-solid fa-qrcode"></i>


                                        <span>
                                            QR
                                        </span>


                                    </div>


                                </div>


                            </div>



                            <!-- =================================================
                                 REFERENCIA
                            ================================================== -->

                            <div
                                class="form-group"
                                id="grupoReferencia"
                                style="display: none;"
                            >


                                <label for="referencia_pago">

                                    Número de referencia *

                                </label>


                                <input
                                    type="text"
                                    id="referencia_pago"
                                    name="referencia_pago"

                                    class="form-control"

                                    maxlength="100"

                                    placeholder="Ej. 458721963"
                                >


                            </div>



                            <!-- =================================================
                                 BOTÓN
                            ================================================== -->

                            <button
                                type="submit"
                                class="btn-payment"
                            >


                                <i class="fa-solid fa-circle-check"></i>


                                Registrar pago


                            </button>


                        </form>


                    <?php else: ?>


                        <div class="empty-state">


                            <i class="fa-solid fa-hand-holding-dollar"></i>


                            <strong>
                                Selecciona una cuenta
                            </strong>


                            <p>
                                Elige una cuenta pendiente para
                                consultar el saldo y registrar un pago.
                            </p>


                        </div>


                    <?php endif; ?>


                </div>


            </section>


        </div>


    </main>


</div>



<script>

/* =========================================================
   SELECCIONAR VENTA
========================================================= */

function seleccionarVenta(id) {

    window.location.href =
        "registrar_pago.php?venta=" + id;
}


/* =========================================================
   BUSCADOR
========================================================= */

const buscador =
    document.getElementById(
        "buscarCliente"
    );


if (buscador) {


    buscador.addEventListener(
        "input",
        function () {


            const texto =
                this.value
                .toLowerCase()
                .trim();


            const cuentas =
                document.querySelectorAll(
                    ".pending-item"
                );


            cuentas.forEach(
                function (cuenta) {


                    const nombre =
                        cuenta.dataset.nombre
                        || "";


                    const documento =
                        cuenta.dataset.documento
                        || "";


                    if (
                        nombre.includes(texto)
                        ||
                        documento.includes(texto)
                    ) {


                        cuenta.style.display =
                            "flex";


                    } else {


                        cuenta.style.display =
                            "none";

                    }

                }
            );

        }
    );

}


/* =========================================================
   MÉTODO DE PAGO
========================================================= */

const metodos =
    document.querySelectorAll(
        ".payment-method"
    );


const metodoPago =
    document.getElementById(
        "metodoPago"
    );


const grupoReferencia =
    document.getElementById(
        "grupoReferencia"
    );


const referencia =
    document.getElementById(
        "referencia_pago"
    );


metodos.forEach(
    function (metodo) {


        metodo.addEventListener(
            "click",
            function () {


                metodos.forEach(
                    function (item) {

                        item.classList.remove(
                            "active"
                        );

                    }
                );


                this.classList.add(
                    "active"
                );


                const valor =
                    this.dataset.metodo;


                metodoPago.value =
                    valor;


                /* QR */

                if (valor === "qr") {


                    grupoReferencia.style.display =
                        "block";


                    referencia.required =
                        true;


                } else {


                    grupoReferencia.style.display =
                        "none";


                    referencia.required =
                        false;


                    referencia.value =
                        "";

                }

            }
        );

    }
);

</script>


</body>

</html><?php

session_start();

require_once "../conexion.php";


/* =========================================================
   PROTEGER MÓDULO
========================================================= */

if (
    !isset($_SESSION["id_usuario"]) ||
    !isset($_SESSION["rol"]) ||
    $_SESSION["rol"] !== "vendedor"
) {

    header("Location: ../index.php");
    exit;
}


/* =========================================================
   DATOS DEL VENDEDOR
========================================================= */

$idVendedor =
    (int) $_SESSION["id_usuario"];

$nombreVendedor =
    $_SESSION["nombre"] ?? "Vendedor";


$inicialVendedor =
    strtoupper(
        substr(
            trim($nombreVendedor),
            0,
            1
        )
    );


/* =========================================================
   VARIABLES
========================================================= */

$mensajeError = "";
$mensajeExito = "";

$ventaSeleccionada = null;


/* =========================================================
   FUNCIÓN PREPARAR CONSULTA
========================================================= */

function prepararConsulta($conn, $sql)
{
    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {

        throw new Exception(
            "Error SQL: " .
            $conn->error
        );
    }

    return $stmt;
}


/* =========================================================
   ID SELECCIONADO
========================================================= */

$idSeleccionado =
    (int) (
        $_GET["venta"]
        ??
        $_POST["venta_id"]
        ??
        0
    );


/* =========================================================
   REGISTRAR PAGO
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    $ventaId =
        (int) (
            $_POST["venta_id"]
            ?? 0
        );


    $monto =
        (float) (
            $_POST["monto"]
            ?? 0
        );


    $metodoPago =
        trim(
            $_POST["metodo_pago"]
            ?? ""
        );


    $referenciaPago =
        trim(
            $_POST["referencia_pago"]
            ?? ""
        );


    /* =====================================================
       VALIDACIONES
    ===================================================== */

    if ($ventaId <= 0) {

        $mensajeError =
            "Selecciona una cuenta pendiente.";

    }

    elseif ($monto <= 0) {

        $mensajeError =
            "Ingresa un valor válido para el pago.";

    }

    elseif (
        !in_array(
            $metodoPago,
            [
                "efectivo",
                "qr"
            ],
            true
        )
    ) {

        $mensajeError =
            "Selecciona un método de pago válido.";

    }

    elseif (
        $metodoPago === "qr" &&
        $referenciaPago === ""
    ) {

        $mensajeError =
            "Ingresa el número de referencia del pago QR.";

    }

    else {


        $conn->begin_transaction();


        try {


            /* =================================================
               CONSULTAR VENTA A CRÉDITO
            ================================================= */

            $sqlVenta = "
                SELECT
                    v.id,
                    v.cliente_id,
                    v.total,
                    v.estado_pago,
                    u.nombre_completo

                FROM ventas v

                INNER JOIN usuarios u
                    ON u.id = v.cliente_id

                WHERE v.id = ?

                AND v.metodo_pago = 'credito'

                FOR UPDATE
            ";


            $stmtVenta =
                prepararConsulta(
                    $conn,
                    $sqlVenta
                );


            $stmtVenta->bind_param(
                "i",
                $ventaId
            );


            $stmtVenta->execute();


            $resultadoVenta =
                $stmtVenta->get_result();


            if (
                $resultadoVenta->num_rows !== 1
            ) {

                throw new Exception(
                    "La cuenta seleccionada no existe."
                );
            }


            $venta =
                $resultadoVenta->fetch_assoc();


            $stmtVenta->close();


            /* =================================================
               CALCULAR ABONOS
            ================================================= */

            $sqlAbonos = "
                SELECT
                    COALESCE(
                        SUM(monto),
                        0
                    ) AS total_abonado

                FROM abonos_credito

                WHERE venta_id = ?
            ";


            $stmtAbonos =
                prepararConsulta(
                    $conn,
                    $sqlAbonos
                );


            $stmtAbonos->bind_param(
                "i",
                $ventaId
            );


            $stmtAbonos->execute();


            $resultadoAbonos =
                $stmtAbonos->get_result();


            $filaAbonos =
                $resultadoAbonos->fetch_assoc();


            $totalAbonado =
                (float) (
                    $filaAbonos["total_abonado"]
                    ?? 0
                );


            $stmtAbonos->close();


            /* =================================================
               SALDO ACTUAL
            ================================================= */

            $saldoActual =
                (float) $venta["total"]
                -
                $totalAbonado;


            if ($saldoActual <= 0) {

                throw new Exception(
                    "Esta cuenta ya está completamente pagada."
                );
            }


            if ($monto > $saldoActual) {

                throw new Exception(
                    "El pago no puede superar el saldo pendiente de $" .
                    number_format(
                        $saldoActual,
                        0,
                        ",",
                        "."
                    ) .
                    "."
                );
            }


            /* =================================================
               REFERENCIA
            ================================================= */

            $referenciaReal = null;


            if ($metodoPago === "qr") {

                $referenciaReal =
                    $referenciaPago;
            }


            /* =================================================
               REGISTRAR ABONO
            ================================================= */

            $sqlRegistrarAbono = "
                INSERT INTO abonos_credito
                (
                    venta_id,
                    monto,
                    metodo_pago,
                    referencia_pago,
                    registrado_por
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ";


            $stmtRegistrar =
                prepararConsulta(
                    $conn,
                    $sqlRegistrarAbono
                );


            $stmtRegistrar->bind_param(
                "idssi",
                $ventaId,
                $monto,
                $metodoPago,
                $referenciaReal,
                $idVendedor
            );


            if (
                !$stmtRegistrar->execute()
            ) {

                throw new Exception(
                    "No fue posible registrar el pago."
                );
            }


            $stmtRegistrar->close();


            /* =================================================
               CALCULAR NUEVO SALDO
            ================================================= */

            $nuevoSaldo =
                $saldoActual -
                $monto;


            if ($nuevoSaldo <= 0) {

                $nuevoSaldo = 0;

                $nuevoEstado =
                    "pagado";

            } else {

                $nuevoEstado =
                    "parcial";
            }


            /* =================================================
               ACTUALIZAR ESTADO DE LA VENTA
            ================================================= */

            $sqlActualizarVenta = "
                UPDATE ventas

                SET estado_pago = ?

                WHERE id = ?
            ";


            $stmtActualizar =
                prepararConsulta(
                    $conn,
                    $sqlActualizarVenta
                );


            $stmtActualizar->bind_param(
                "si",
                $nuevoEstado,
                $ventaId
            );


            if (
                !$stmtActualizar->execute()
            ) {

                throw new Exception(
                    "No fue posible actualizar el estado de la cuenta."
                );
            }


            $stmtActualizar->close();


            /* =================================================
               CONFIRMAR
            ================================================= */

            $conn->commit();


            $mensajeExito =
                "Pago registrado correctamente. Saldo pendiente: $" .
                number_format(
                    $nuevoSaldo,
                    0,
                    ",",
                    "."
                ) .
                ".";


            /*
             * Si quedó completamente pagada,
             * quitamos la selección.
             */

            if ($nuevoSaldo <= 0) {

                $idSeleccionado = 0;

            } else {

                $idSeleccionado =
                    $ventaId;
            }


        } catch (Throwable $e) {


            $conn->rollback();


            $mensajeError =
                $e->getMessage();

        }

    }

}


/* =========================================================
   CONSULTAR CUENTAS PENDIENTES
========================================================= */

$cuentasPendientes = [];


$sqlCuentas = "
    SELECT

        v.id,
        v.cliente_id,
        v.total,
        v.estado_pago,
        v.fecha,

        u.nombre_completo,
        u.documento,
        u.rol,
        u.grado,

        COALESCE(
            SUM(a.monto),
            0
        ) AS total_abonado

    FROM ventas v

    INNER JOIN usuarios u
        ON u.id = v.cliente_id

    LEFT JOIN abonos_credito a
        ON a.venta_id = v.id

    WHERE
        v.metodo_pago = 'credito'

    AND
        v.estado_pago IN (
            'pendiente',
            'parcial'
        )

    GROUP BY
        v.id,
        v.cliente_id,
        v.total,
        v.estado_pago,
        v.fecha,
        u.nombre_completo,
        u.documento,
        u.rol,
        u.grado

    HAVING
        (
            v.total
            -
            COALESCE(
                SUM(a.monto),
                0
            )
        ) > 0

    ORDER BY
        v.fecha ASC
";


$resultadoCuentas =
    $conn->query(
        $sqlCuentas
    );


if ($resultadoCuentas) {


    while (
        $fila =
        $resultadoCuentas->fetch_assoc()
    ) {


        $fila["saldo"] =
            (float) $fila["total"]
            -
            (float) $fila["total_abonado"];


        $cuentasPendientes[] =
            $fila;

    }


} else {


    $mensajeError =
        "No fue posible consultar las cuentas pendientes: " .
        $conn->error;

}


/* =========================================================
   BUSCAR CUENTA SELECCIONADA
========================================================= */

foreach (
    $cuentasPendientes
    as
    $cuenta
) {


    if (
        (int) $cuenta["id"] ===
        $idSeleccionado
    ) {


        $ventaSeleccionada =
            $cuenta;

        break;

    }

}


/* =========================================================
   FUNCIÓN PARA MOSTRAR ROL
========================================================= */

function mostrarRol($cuenta)
{

    if (
        $cuenta["rol"] ===
        "estudiante"
    ) {


        $texto =
            "Estudiante";


        if (
            !empty(
                $cuenta["grado"]
            )
        ) {

            $texto .=
                " · Grado " .
                $cuenta["grado"] .
                "°";
        }


        return $texto;

    }


    if (
        $cuenta["rol"] ===
        "profesor"
    ) {

        return "Profesor";
    }


    if (
        $cuenta["rol"] ===
        "administrativo"
    ) {

        return "Planta administrativa";
    }


    return ucfirst(
        $cuenta["rol"]
    );
}

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Registrar pago | Cafetería Inteligente
    </title>


    <link
        rel="stylesheet"
        href="registrar_pago.css?v=<?php echo time(); ?>"
    >


    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

</head>


<body>


<div class="app">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar">


        <!-- LOGO -->

        <div class="sidebar-brand">


            <img
                src="https://www.colegioliceomoderno.edu.co/img/logoclm500px.png"
                alt="Colegio Liceo Moderno"
            >


            <div>

                <strong>
                    Cafetería
                </strong>

                <span>
                    Inteligente
                </span>

            </div>


        </div>



        <!-- =================================================
             MENÚ
        ================================================== -->

        <nav class="sidebar-menu">


            <a href="inicio.php">

                <i class="fa-solid fa-house"></i>

                Inicio

            </a>


            <a href="pedidos.php">

                <i class="fa-solid fa-receipt"></i>

                Pedidos

            </a>


            <a href="nueva_venta.php">

                <i class="fa-solid fa-cart-plus"></i>

                Nueva venta

            </a>


            <a href="productos.php">

                <i class="fa-solid fa-box"></i>

                Productos

            </a>


            <a href="inventario.php">

                <i class="fa-solid fa-boxes-stacked"></i>

                Inventario

            </a>


            <a
                href="creditos.php"
                class="active"
            >

                <i class="fa-solid fa-hand-holding-dollar"></i>

                Cuentas por cobrar

            </a>


            <a href="ventas.php">

                <i class="fa-solid fa-clock-rotate-left"></i>

                Historial

            </a>


        </nav>



        <!-- =================================================
             PERFIL
        ================================================== -->

        <a
            href="perfil.php"
            class="sidebar-user"
        >


            <div class="user-avatar">

                <?php

                echo htmlspecialchars(
                    $inicialVendedor,
                    ENT_QUOTES,
                    "UTF-8"
                );

                ?>

            </div>


            <div>


                <strong>

                    <?php

                    echo htmlspecialchars(
                        $nombreVendedor,
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    ?>

                </strong>


                <span>
                    Vendedor
                </span>


            </div>


        </a>



        <!-- =================================================
             CERRAR SESIÓN
        ================================================== -->

        <a
            href="../cerrar_sesion.php"
            class="logout"
        >

            <i class="fa-solid fa-right-from-bracket"></i>

            Cerrar sesión

        </a>


    </aside>



    <!-- =====================================================
         CONTENIDO
    ====================================================== -->

    <main class="main-content">


        <a
            href="inicio.php"
            class="back-link"
        >

            <i class="fa-solid fa-arrow-left"></i>

            Volver al inicio

        </a>



        <!-- =================================================
             HEADER
        ================================================== -->

        <header class="page-header">


            <h1>
                Registrar pago
            </h1>


            <p>
                Registra un abono o el pago total de una cuenta pendiente.
            </p>


        </header>



        <!-- =================================================
             MENSAJES
        ================================================== -->

        <?php if ($mensajeError !== ""): ?>


            <div class="alert alert-error">


                <i class="fa-solid fa-circle-exclamation"></i>


                <span>

                    <?php

                    echo htmlspecialchars(
                        $mensajeError,
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    ?>

                </span>


            </div>


        <?php endif; ?>



        <?php if ($mensajeExito !== ""): ?>


            <div class="alert alert-success">


                <i class="fa-solid fa-circle-check"></i>


                <span>

                    <?php

                    echo htmlspecialchars(
                        $mensajeExito,
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    ?>

                </span>


            </div>


        <?php endif; ?>



        <!-- =================================================
             DOS COLUMNAS
        ================================================== -->

        <div class="payment-container">


            <!-- =============================================
                 CUENTAS PENDIENTES
            ============================================== -->

            <section class="card">


                <div class="card-header">


                    <h2>
                        Cuentas pendientes
                    </h2>


                    <p>
                        Selecciona la persona que realizará el pago.
                    </p>


                </div>



                <div class="card-body">


                    <!-- BUSCADOR -->

                    <div class="search-box">


                        <i class="fa-solid fa-magnifying-glass"></i>


                        <input
                            type="text"
                            id="buscarCliente"
                            placeholder="Buscar por nombre o documento"
                        >


                    </div>



                    <?php if (
                        count($cuentasPendientes) > 0
                    ): ?>


                        <div
                            class="pending-list"
                            id="listaCreditos"
                        >


                            <?php foreach (
                                $cuentasPendientes
                                as
                                $cuenta
                            ): ?>


                                <div
                                    class="pending-item <?php

                                    echo (
                                        (int) $cuenta["id"]
                                        ===
                                        $idSeleccionado
                                    )
                                        ? "selected"
                                        : "";

                                    ?>"

                                    data-nombre="<?php

                                    echo htmlspecialchars(
                                        strtolower(
                                            $cuenta[
                                                "nombre_completo"
                                            ]
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>"

                                    data-documento="<?php

                                    echo htmlspecialchars(
                                        $cuenta["documento"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>"

                                    onclick="seleccionarVenta(
                                        <?php
                                        echo (int) $cuenta["id"];
                                        ?>
                                    )"
                                >


                                    <div class="pending-info">


                                        <strong>

                                            <?php

                                            echo htmlspecialchars(
                                                $cuenta[
                                                    "nombre_completo"
                                                ],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            );

                                            ?>

                                        </strong>


                                        <span>

                                            Documento:
                                            <?php

                                            echo htmlspecialchars(
                                                $cuenta[
                                                    "documento"
                                                ],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            );

                                            ?>

                                        </span>


                                        <span>

                                            <?php

                                            echo htmlspecialchars(
                                                mostrarRol(
                                                    $cuenta
                                                ),
                                                ENT_QUOTES,
                                                "UTF-8"
                                            );

                                            ?>

                                        </span>


                                        <span>

                                            Cuenta #
                                            <?php
                                            echo (int) $cuenta["id"];
                                            ?>

                                        </span>


                                    </div>



                                    <div class="pending-amount">


                                        $

                                        <?php

                                        echo number_format(
                                            $cuenta["saldo"],
                                            0,
                                            ",",
                                            "."
                                        );

                                        ?>


                                    </div>


                                </div>


                            <?php endforeach; ?>


                        </div>


                    <?php else: ?>


                        <div class="empty-state">


                            <i class="fa-solid fa-circle-check"></i>


                            <strong>
                                No hay cuentas pendientes
                            </strong>


                            <p>
                                Cuando registres una venta a crédito,
                                aparecerá aquí para recibir pagos o abonos.
                            </p>


                        </div>


                    <?php endif; ?>


                </div>


            </section>



            <!-- =============================================
                 DETALLE DEL PAGO
            ============================================== -->

            <section class="card">


                <div class="card-header">


                    <h2>
                        Detalle del pago
                    </h2>


                    <p>
                        Revisa la información antes de registrar el abono.
                    </p>


                </div>



                <div class="card-body">


                    <?php if (
                        $ventaSeleccionada
                    ): ?>


                        <!-- CLIENTE -->

                        <div class="client-info">


                            <div class="client-info-row">


                                <span>
                                    Cliente
                                </span>


                                <strong>

                                    <?php

                                    echo htmlspecialchars(
                                        $ventaSeleccionada[
                                            "nombre_completo"
                                        ],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="client-info-row">


                                <span>
                                    Documento
                                </span>


                                <strong>

                                    <?php

                                    echo htmlspecialchars(
                                        $ventaSeleccionada[
                                            "documento"
                                        ],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="client-info-row">


                                <span>
                                    Tipo
                                </span>


                                <strong>

                                    <?php

                                    echo htmlspecialchars(
                                        mostrarRol(
                                            $ventaSeleccionada
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    ?>

                                </strong>


                            </div>


                        </div>



                        <!-- =================================================
                             DEUDA
                        ================================================== -->

                        <div class="debt-summary">


                            <div class="debt-row">


                                <span>
                                    Valor inicial
                                </span>


                                <strong>

                                    $

                                    <?php

                                    echo number_format(
                                        $ventaSeleccionada[
                                            "total"
                                        ],
                                        0,
                                        ",",
                                        "."
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="debt-row">


                                <span>
                                    Total abonado
                                </span>


                                <strong>

                                    $

                                    <?php

                                    echo number_format(
                                        $ventaSeleccionada[
                                            "total_abonado"
                                        ],
                                        0,
                                        ",",
                                        "."
                                    );

                                    ?>

                                </strong>


                            </div>



                            <div class="debt-row total">


                                <span>
                                    Saldo pendiente
                                </span>


                                <strong>

                                    $

                                    <?php

                                    echo number_format(
                                        $ventaSeleccionada[
                                            "saldo"
                                        ],
                                        0,
                                        ",",
                                        "."
                                    );

                                    ?>

                                </strong>


                            </div>


                        </div>



                        <!-- =================================================
                             FORMULARIO
                        ================================================== -->

                        <form
                            method="POST"
                            class="payment-form"
                        >


                            <input
                                type="hidden"
                                name="venta_id"

                                value="<?php
                                echo (int)
                                    $ventaSeleccionada["id"];
                                ?>"
                            >



                            <!-- VALOR -->

                            <div class="form-group">


                                <label for="monto">

                                    Valor del pago *

                                </label>


                                <div class="money-input">


                                    <span>
                                        $
                                    </span>


                                    <input
                                        type="number"
                                        id="monto"
                                        name="monto"

                                        class="form-control"

                                        min="1"

                                        max="<?php
                                        echo (float)
                                            $ventaSeleccionada[
                                                "saldo"
                                            ];
                                        ?>"

                                        step="1"

                                        placeholder="0"

                                        required
                                    >


                                </div>


                            </div>



                            <!-- =================================================
                                 MÉTODO
                            ================================================== -->

                            <div class="form-group">


                                <label>
                                    Método de pago *
                                </label>


                                <input
                                    type="hidden"
                                    id="metodoPago"
                                    name="metodo_pago"
                                    value="efectivo"
                                >


                                <div class="payment-methods">


                                    <div
                                        class="payment-method active"
                                        data-metodo="efectivo"
                                    >


                                        <i class="fa-solid fa-money-bill-wave"></i>


                                        <span>
                                            Efectivo
                                        </span>


                                    </div>



                                    <div
                                        class="payment-method"
                                        data-metodo="qr"
                                    >


                                        <i class="fa-solid fa-qrcode"></i>


                                        <span>
                                            QR
                                        </span>


                                    </div>


                                </div>


                            </div>



                            <!-- =================================================
                                 REFERENCIA
                            ================================================== -->

                            <div
                                class="form-group"
                                id="grupoReferencia"
                                style="display: none;"
                            >


                                <label for="referencia_pago">

                                    Número de referencia *

                                </label>


                                <input
                                    type="text"
                                    id="referencia_pago"
                                    name="referencia_pago"

                                    class="form-control"

                                    maxlength="100"

                                    placeholder="Ej. 458721963"
                                >


                            </div>



                            <!-- =================================================
                                 BOTÓN
                            ================================================== -->

                            <button
                                type="submit"
                                class="btn-payment"
                            >


                                <i class="fa-solid fa-circle-check"></i>


                                Registrar pago


                            </button>


                        </form>


                    <?php else: ?>


                        <div class="empty-state">


                            <i class="fa-solid fa-hand-holding-dollar"></i>


                            <strong>
                                Selecciona una cuenta
                            </strong>


                            <p>
                                Elige una cuenta pendiente para
                                consultar el saldo y registrar un pago.
                            </p>


                        </div>


                    <?php endif; ?>


                </div>


            </section>


        </div>


    </main>


</div>



<script>

/* =========================================================
   SELECCIONAR VENTA
========================================================= */

function seleccionarVenta(id) {

    window.location.href =
        "registrar_pago.php?venta=" + id;
}


/* =========================================================
   BUSCADOR
========================================================= */

const buscador =
    document.getElementById(
        "buscarCliente"
    );


if (buscador) {


    buscador.addEventListener(
        "input",
        function () {


            const texto =
                this.value
                .toLowerCase()
                .trim();


            const cuentas =
                document.querySelectorAll(
                    ".pending-item"
                );


            cuentas.forEach(
                function (cuenta) {


                    const nombre =
                        cuenta.dataset.nombre
                        || "";


                    const documento =
                        cuenta.dataset.documento
                        || "";


                    if (
                        nombre.includes(texto)
                        ||
                        documento.includes(texto)
                    ) {


                        cuenta.style.display =
                            "flex";


                    } else {


                        cuenta.style.display =
                            "none";

                    }

                }
            );

        }
    );

}


/* =========================================================
   MÉTODO DE PAGO
========================================================= */

const metodos =
    document.querySelectorAll(
        ".payment-method"
    );


const metodoPago =
    document.getElementById(
        "metodoPago"
    );


const grupoReferencia =
    document.getElementById(
        "grupoReferencia"
    );


const referencia =
    document.getElementById(
        "referencia_pago"
    );


metodos.forEach(
    function (metodo) {


        metodo.addEventListener(
            "click",
            function () {


                metodos.forEach(
                    function (item) {

                        item.classList.remove(
                            "active"
                        );

                    }
                );


                this.classList.add(
                    "active"
                );


                const valor =
                    this.dataset.metodo;


                metodoPago.value =
                    valor;


                /* QR */

                if (valor === "qr") {


                    grupoReferencia.style.display =
                        "block";


                    referencia.required =
                        true;


                } else {


                    grupoReferencia.style.display =
                        "none";


                    referencia.required =
                        false;


                    referencia.value =
                        "";

                }

            }
        );

    }
);

</script>


</body>

</html>
</body>
</html>
</body>
</html>
