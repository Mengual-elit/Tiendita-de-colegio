<script>

        // Guarda los productos seleccionados
        let carrito = [];

        // Agrega un producto
        function agregarProducto(nombre, precio) {

            carrito.push({
                nombre: nombre,
                precio: precio
            });

            actualizarCarrito();

        }

        // Actualiza la lista y el total
        function actualizarCarrito() {

            let lista = document.getElementById("listaCarrito");
            let total = 0;

            lista.innerHTML = "";

            carrito.forEach(function(producto) {

                let elemento = document.createElement("li");

                elemento.textContent =
                    producto.nombre +
                    " - $" +
                    producto.precio.toLocaleString("es-CO");

                lista.appendChild(elemento);

                total += producto.precio;

            });

            // Si no hay productos
            if (carrito.length === 0) {

                lista.innerHTML =
                    "<li>No has agregado productos.</li>";

            }

            document.getElementById("total").textContent =
                total.toLocaleString("es-CO");

        }

        // Confirma la reserva
        function reservarCompra(event) {

            event.preventDefault();

            let nombre =
                document.getElementById("nombre").value;

            let curso =
                document.getElementById("curso").value;

            let hora =
                document.getElementById("hora").value;

            // Revisa si hay productos
            if (carrito.length === 0) {

                alert(
                    "Debes agregar al menos un producto."
                );

                return;

            }

            alert(
                "¡Reserva realizada con éxito! 🎉\n\n" +
                "Estudiante: " + nombre + "\n" +
                "Curso: " + curso + "\n" +
                "Hora de recogida: " + hora
            );

            // Limpia el carrito
            carrito = [];

            actualizarCarrito();

            // Limpia el formulario
            document.querySelector("form").reset();

        }

    </script>