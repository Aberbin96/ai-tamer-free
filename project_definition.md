Documento de Definición de Problemas
Estado: Definición de MVP (Producto Mínimo Viable)
Fecha: Marzo 2026
Objetivo: Resolver la vulnerabilidad inmediata del contenido de WordPress frente al rastreo no autorizado de IA.

1. El Dilema del "Todo o Nada" (SEO vs. Entrenamiento)
   Actualmente, los dueños de sitios web se enfrentan a una elección binaria imposible.

El Problema: Bloquear un bot de IA (como GPTBot o Google-Extended) suele significar quedar fuera de las funciones de búsqueda y citación (SearchGPT, Gemini, Perplexity), lo que destruye el tráfico entrante. Pero permitir el acceso significa regalar el contenido para el entrenamiento de modelos sin compensación.

Impacto en V1: Falta de un sistema de "triaje" que permita diferenciar el rastreo con fines de indexación/tráfico del rastreo con fines de extracción de datos masiva.

Qué debemos lograr: Que el usuario pueda elegir si quiere que lo encuentren en buscadores de IA (como Perplexity) sin que eso signifique regalar sus datos para que la IA aprenda a escribir como él. Debemos dar un control selectivo.

Qué NO debemos hacer: Bloquear a la IA de forma "ciega" que cause que el sitio desaparezca de Google. No debemos forzar al usuario a elegir entre "ser invisible" o "ser robado".

2. Ineficiencia de los Estándares Actuales (Robots.txt)
   El archivo robots.txt es una tecnología de hace 30 años que no es suficiente para la era de la IA generativa.

El Problema: El robots.txt es una "sugerencia" que muchos scrapers agresivos ignoran. Además, no permite proteger elementos específicos dentro de una página (como un párrafo sensible o una imagen premium).

Impacto en V1: Los editores no tienen una forma de inyectar metadatos modernos (como noai o noimageai) de forma automática y global en todo su contenido HTML sin tocar código.

Qué debemos lograr: Crear una defensa multi-capa. Si el bot ignora el archivo robots.txt, debe encontrarse con etiquetas dentro del código y cabeceras de servidor que le digan "No". Debemos ser redundantes para ser efectivos.

Qué NO debemos hacer: Confiar solo en los métodos tradicionales. No debemos permitir que la protección dependa de que el bot "sea educado" y respete las señales de tráfico estándar de internet.

3. La "Caja Negra" del Rastreo (Falta de Visibilidad)
   Los administradores de WordPress están "ciegos" ante lo que sucede en sus servidores.

El Problema: WordPress no registra cuántas veces un bot de IA ha "consumido" un artículo específico. El dueño de la web no sabe si su contenido está siendo usado 1 vez o 1,000 veces al día por modelos de lenguaje.

Impacto en V1: Sin datos de consumo (logs), el editor no puede valorar cuánto vale su contenido ni tomar decisiones informadas sobre a quién bloquear.

Qué debemos lograr: Darle al usuario una ventana a su servidor. Debe saber exactamente qué empresas de IA están visitando su web, qué artículos les interesan más y con qué frecuencia lo hacen.

Qué NO debemos hacer: Guardar datos innecesarios de usuarios humanos. No debemos convertir el plugin en una herramienta de analítica general (tipo Google Analytics) ni saturar la base de datos de la web con registros basura que la vuelvan lenta.

4. Extracción de Contenido "Sucio" y No Estructurado
   Cuando una IA hace scraping, no distingue entre el contenido editorial y el ruido de la web.

El Problema: Los agentes de IA succionan menús, anuncios, widgets de pie de página y barras laterales. Esto no solo ensucia los datos de entrenamiento, sino que consume recursos innecesarios del servidor del editor (ancho de banda y CPU) para entregar contenido que no es el artículo principal.

Impacto en V1: Necesidad de definir qué partes del HTML son "consumibles por IA" y cuáles deben ser invisibles para los bots, protegiendo la integridad del sitio.

Qué debemos lograr: Definir qué es contenido esencial y qué es "ruido" (anuncios, menús, botones). Debemos asegurar que, si una IA entra, solo pueda ver (o se le prohíba ver) lo que realmente tiene valor editorial.

Qué NO debemos hacer: Alterar la experiencia visual del lector humano. No debemos romper el diseño de la web ni ocultar elementos que el usuario real necesita para navegar solo por querer esconderlos de la IA.

5. El Vacío de Identificación de Agentes (Cloaking)
   Muchos agentes de IA de "larga cola" (startups de IA pequeñas) no se identifican honestamente.

El Problema: Existen cientos de agentes RAG (Retrieval-Augmented Generation) que no usan User-Agents conocidos, haciendo que pasen como usuarios normales mientras extraen contenido sistemáticamente.

Impacto en V1: Los plugins actuales son estáticos. Si aparece una nueva IA mañana, el plugin queda obsoleto. Falta un sistema de detección dinámica o una lista negra centralizada y actualizable.

Qué debemos lograr: Mantener la protección siempre actualizada. El plugin debe saber hoy que existe una nueva IA que salió ayer, sin que el usuario tenga que configurar nada manualmente.

Qué NO debemos hacer: Intentar jugar al "gato y el ratón" con técnicas de detección ultra-complejas que den falsos positivos. No debemos bloquear por error a un usuario real pensando que es una IA, ya que eso arruinaría el negocio del cliente.

6. Fragmentación de Licencias "Legibles por Máquina"
   El problema de los contratos manuales que mencionaste empieza aquí.

El Problema: Incluso si una IA quisiera "portarse bien", no hay una forma estándar en WordPress de que el sitio le diga: "Puedes leer esto, pero no puedes guardarlo" o "Cítame de esta manera".

Impacto en V1: Falta de una cabecera HTTP o etiqueta meta que exponga la "intención de licencia" del autor de forma automática.

Qué debemos lograr: Declarar la intención legal del autor de forma clara y automática. El sitio debe "gritar" sus condiciones de uso en un lenguaje que las IAs procesen antes de empezar a leer.

Qué NO debemos hacer: Intentar gestionar pagos, contratos legales complejos o abogados en esta fase. No debemos prometer que el plugin "hace cumplir la ley", sino que "declara la voluntad del autor" de forma técnica.

7. El "Impuesto Oculto" de Recursos (Carga del Servidor)
   El Problema: El rastreo masivo de IA no solo "roba" contenido, sino que consume ancho de banda y capacidad de procesamiento (CPU/RAM) de tu hosting. Un ataque de scraping de 50 bots simultáneos puede tirar una web pequeña o hacer que el dueño pague de más en su factura de hosting.

Qué debemos lograr: Asegurar que el sitio siga siendo rápido y funcional para los humanos, limitando el impacto que los bots tienen en la infraestructura de la web.

Qué NO debemos hacer: Implementar sistemas de protección tan pesados que terminen consumiendo más recursos que los propios bots. La cura no puede ser más cara que la enfermedad.

8. La Ausencia de "Prueba de Rastreo" (Evidencia Forense)
   El Problema: En el futuro (o ahora mismo), si un autor quiere demandar a una empresa de IA por usar su contenido sin permiso, necesita pruebas. Actualmente, no hay forma fácil en WordPress de demostrar que "la IA X entró en tal fecha y se llevó tal artículo".

Qué debemos lograr: Generar un rastro de auditoría o historial que el autor pueda exportar para demostrar fehacientemente que su propiedad intelectual fue accedida por agentes específicos.

Qué NO debemos hacer: Intentar ser un "juez" o una plataforma legal. No debemos dar asesoría jurídica, solo proporcionar los datos técnicos de lo que ocurrió en el servidor.
