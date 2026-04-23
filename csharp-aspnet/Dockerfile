FROM mcr.microsoft.com/dotnet/sdk:9.0 AS builder

WORKDIR /app
COPY *.csproj ./
RUN dotnet restore

COPY *.cs ./
RUN dotnet publish -c Release -o out --no-restore

FROM mcr.microsoft.com/dotnet/aspnet:9.0

WORKDIR /app
COPY --from=builder /app/out .

EXPOSE 8000
CMD ["dotnet", "chopchop.dll"]
