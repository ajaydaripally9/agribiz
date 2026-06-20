# Use the official Node.js Alpine image for a lightweight runtime
FROM node:20-alpine

# Set working directory inside the container
WORKDIR /app

# Copy package.json and package-lock.json first to cache layers
COPY package*.json ./

# Install production dependencies
RUN npm ci --only=production

# Copy the rest of the application code
COPY . .

# Expose the application port
EXPOSE 8000

# Start the Node.js application
CMD ["node", "server.js"]
