FROM maven:3.9-eclipse-temurin-21 AS builder

WORKDIR /app
COPY pom.xml .
# Download dependencies first (cached layer)
RUN mvn dependency:go-offline -q

COPY src src
RUN mvn package -DskipTests -q

FROM eclipse-temurin:21-jre-jammy

WORKDIR /app
COPY --from=builder /app/target/*.jar app.jar

EXPOSE 8000
CMD ["java", "-jar", "app.jar"]
