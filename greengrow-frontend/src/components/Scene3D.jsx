import React, { useRef, useMemo } from 'react';
import { Canvas, useFrame } from '@react-three/fiber';
import { Environment, Float, OrbitControls, Sparkles, ContactShadows, Stars } from '@react-three/drei';
import * as THREE from 'three';

// Floating leaves component
function Leaves() {
  const count = 30;
  const mesh = useRef();
  
  const dummy = useMemo(() => new THREE.Object3D(), []);
  const particles = useMemo(() => {
    const temp = [];
    for (let i = 0; i < count; i++) {
      const t = Math.random() * 100;
      const factor = 20 + Math.random() * 100;
      const speed = 0.01 + Math.random() / 200;
      const xFactor = -10 + Math.random() * 20;
      const yFactor = -10 + Math.random() * 20;
      const zFactor = -10 + Math.random() * 20;
      temp.push({ t, factor, speed, xFactor, yFactor, zFactor, mx: 0, my: 0 });
    }
    return temp;
  }, [count]);

  useFrame((state) => {
    particles.forEach((particle, i) => {
      let { t, factor, speed, xFactor, yFactor, zFactor } = particle;
      t = particle.t += speed / 2;
      const a = Math.cos(t) + Math.sin(t * 1) / 10;
      const b = Math.sin(t) + Math.cos(t * 2) / 10;
      const s = Math.max(0.2, Math.cos(t));
      dummy.position.set(
        (particle.mx / 10) * a + xFactor + Math.cos((t / 10) * factor) + (Math.sin(t * 1) * factor) / 10,
        (particle.my / 10) * b + yFactor + Math.sin((t / 10) * factor) + (Math.cos(t * 2) * factor) / 10,
        (particle.my / 10) * b + zFactor + Math.cos((t / 10) * factor) + (Math.sin(t * 3) * factor) / 10
      );
      dummy.scale.set(s, s, s);
      dummy.rotation.set(s * 5, s * 5, s * 5);
      dummy.updateMatrix();
      mesh.current.setMatrixAt(i, dummy.matrix);
    });
    mesh.current.instanceMatrix.needsUpdate = true;
  });

  return (
    <instancedMesh ref={mesh} args={[null, null, count]}>
      <coneGeometry args={[0.2, 0.5, 3]} />
      <meshStandardMaterial color="#39ff14" roughness={0.1} />
    </instancedMesh>
  );
}

// Fertilizer Bag Mock
function FertilizerBag(props) {
  return (
    <Float speed={2} rotationIntensity={0.5} floatIntensity={1}>
      <group {...props}>
        {/* Main Bag Body */}
        <mesh position={[0, 1.5, 0]}>
          <boxGeometry args={[2, 3, 1]} />
          <meshStandardMaterial color="#ffffff" roughness={0.4} />
        </mesh>
        
        {/* Green Bottom Accent */}
        <mesh position={[0, 0.5, 0.01]}>
          <boxGeometry args={[2.02, 1, 1.02]} />
          <meshStandardMaterial color="#059669" roughness={0.6} />
        </mesh>

        {/* Top Seal */}
        <mesh position={[0, 3.1, 0]}>
          <cylinderGeometry args={[1, 1.2, 0.2, 4]} />
          <meshStandardMaterial color="#e5e7eb" roughness={0.8} />
        </mesh>
        
        {/* Logo Mock */}
        <mesh position={[0, 1.8, 0.51]}>
          <planeGeometry args={[1, 1]} />
          <meshStandardMaterial color="#10B981" />
        </mesh>
      </group>
    </Float>
  );
}

// Floating Island
function Island() {
  const ringRef = useRef();

  useFrame((state) => {
    if (ringRef.current) {
      ringRef.current.rotation.z = state.clock.elapsedTime * 0.5;
    }
  });

  return (
    <group position={[0, -2, 0]}>
      {/* Soil base */}
      <mesh position={[0, 0, 0]} castShadow receiveShadow>
        <cylinderGeometry args={[4, 3, 1, 32]} />
        <meshStandardMaterial color="#3d2817" roughness={1} />
      </mesh>
      
      {/* Grass Top */}
      <mesh position={[0, 0.55, 0]} receiveShadow>
        <cylinderGeometry args={[4, 4, 0.1, 32]} />
        <meshStandardMaterial color="#1a4d27" roughness={0.8} />
      </mesh>

      {/* Glowing Ring */}
      <mesh ref={ringRef} position={[0, 1, 0]} rotation={[-Math.PI / 2, 0, 0]}>
        <torusGeometry args={[4.5, 0.05, 16, 100]} />
        <meshBasicMaterial color="#39ff14" transparent opacity={0.8} />
      </mesh>
      
      {/* Mini Plants on Island */}
      <group position={[-2, 1, 1]}>
        <mesh position={[0, 0.5, 0]}><coneGeometry args={[0.2, 1, 4]}/><meshStandardMaterial color="#39ff14"/></mesh>
      </group>
      <group position={[2.5, 1, -1]}>
        <mesh position={[0, 0.3, 0]}><coneGeometry args={[0.15, 0.6, 4]}/><meshStandardMaterial color="#10B981"/></mesh>
      </group>
      <group position={[1.5, 1, 2]}>
        <mesh position={[0, 0.4, 0]}><coneGeometry args={[0.2, 0.8, 4]}/><meshStandardMaterial color="#39ff14"/></mesh>
      </group>
    </group>
  );
}

// Mini Tractor Component
function Tractor(props) {
  return (
    <group {...props}>
      {/* Tractor Body (Engine) */}
      <mesh position={[0, 0.4, 0.3]}>
        <boxGeometry args={[0.8, 0.5, 1]} />
        <meshStandardMaterial color="#4ade80" />
      </mesh>
      
      {/* Cabin */}
      <mesh position={[0, 0.8, -0.2]}>
        <boxGeometry args={[0.8, 0.6, 0.6]} />
        <meshStandardMaterial color="#22c55e" />
      </mesh>
      
      {/* Cabin Window */}
      <mesh position={[0, 0.85, -0.2]}>
        <boxGeometry args={[0.82, 0.4, 0.4]} />
        <meshStandardMaterial color="#1f2937" transparent opacity={0.8} />
      </mesh>

      {/* Roof */}
      <mesh position={[0, 1.15, -0.2]}>
        <boxGeometry args={[0.9, 0.1, 0.7]} />
        <meshStandardMaterial color="#166534" />
      </mesh>

      {/* Rear Wheels (Large) */}
      <group position={[0.45, 0.4, -0.2]} rotation={[0, 0, -Math.PI / 2]}>
        <mesh><cylinderGeometry args={[0.4, 0.4, 0.2, 16]}/><meshStandardMaterial color="#111827"/></mesh>
        <mesh position={[0, 0.11, 0]}><cylinderGeometry args={[0.2, 0.2, 0.05, 16]}/><meshStandardMaterial color="#facc15"/></mesh>
      </group>
      <group position={[-0.45, 0.4, -0.2]} rotation={[0, 0, -Math.PI / 2]}>
        <mesh><cylinderGeometry args={[0.4, 0.4, 0.2, 16]}/><meshStandardMaterial color="#111827"/></mesh>
        <mesh position={[0, -0.11, 0]}><cylinderGeometry args={[0.2, 0.2, 0.05, 16]}/><meshStandardMaterial color="#facc15"/></mesh>
      </group>

      {/* Front Wheels (Small) */}
      <group position={[0.4, 0.2, 0.6]} rotation={[0, 0, -Math.PI / 2]}>
        <mesh><cylinderGeometry args={[0.2, 0.2, 0.15, 16]}/><meshStandardMaterial color="#111827"/></mesh>
        <mesh position={[0, 0.08, 0]}><cylinderGeometry args={[0.1, 0.1, 0.05, 16]}/><meshStandardMaterial color="#facc15"/></mesh>
      </group>
      <group position={[-0.4, 0.2, 0.6]} rotation={[0, 0, -Math.PI / 2]}>
        <mesh><cylinderGeometry args={[0.2, 0.2, 0.15, 16]}/><meshStandardMaterial color="#111827"/></mesh>
        <mesh position={[0, -0.08, 0]}><cylinderGeometry args={[0.1, 0.1, 0.05, 16]}/><meshStandardMaterial color="#facc15"/></mesh>
      </group>

      {/* Exhaust Pipe */}
      <mesh position={[0.3, 0.9, 0.6]}>
        <cylinderGeometry args={[0.05, 0.05, 0.6]} />
        <meshStandardMaterial color="#4b5563" />
      </mesh>
    </group>
  );
}

// Tractor Island
function TractorIsland(props) {
  return (
    <Float speed={1.5} rotationIntensity={0.2} floatIntensity={0.5}>
      <group {...props}>
        {/* Dirt Base */}
        <mesh position={[0, 0, 0]} castShadow receiveShadow>
          <cylinderGeometry args={[2, 1.5, 0.8, 16]} />
          <meshStandardMaterial color="#3d2817" roughness={1} />
        </mesh>
        {/* Grass Top */}
        <mesh position={[0, 0.45, 0]} receiveShadow>
          <cylinderGeometry args={[2, 2, 0.1, 16]} />
          <meshStandardMaterial color="#1a4d27" roughness={0.8} />
        </mesh>
        
        {/* Tractor */}
        <Tractor position={[0, 0.5, 0]} rotation={[0, -Math.PI / 4, 0]} />

        {/* Tree */}
        <group position={[1, 0.5, -0.5]}>
          <mesh position={[0, 0.3, 0]}><cylinderGeometry args={[0.1, 0.1, 0.6]}/><meshStandardMaterial color="#5c4033"/></mesh>
          <mesh position={[0, 0.8, 0]}><sphereGeometry args={[0.5, 8, 8]}/><meshStandardMaterial color="#22c55e"/></mesh>
          <mesh position={[0.2, 1, 0.2]}><sphereGeometry args={[0.4, 8, 8]}/><meshStandardMaterial color="#16a34a"/></mesh>
          <mesh position={[-0.2, 0.9, -0.2]}><sphereGeometry args={[0.3, 8, 8]}/><meshStandardMaterial color="#15803d"/></mesh>
        </group>
        
        {/* Mini clouds below island */}
        <mesh position={[-1.5, -0.5, 1]}><sphereGeometry args={[0.3, 8, 8]}/><meshStandardMaterial color="#ffffff" transparent opacity={0.6}/></mesh>
        <mesh position={[1.5, -0.2, -1]}><sphereGeometry args={[0.4, 8, 8]}/><meshStandardMaterial color="#ffffff" transparent opacity={0.6}/></mesh>
      </group>
    </Float>
  );
}

export default function Scene3D() {
  return (
    <div className="w-full h-full absolute inset-0">
      <Canvas camera={{ position: [0, 2, 12], fov: 45 }}>
        {/* Lighting */}
        <ambientLight intensity={0.2} />
        <spotLight position={[10, 10, 10]} angle={0.15} penumbra={1} intensity={1} castShadow color="#e0ffe5" />
        <pointLight position={[-10, -10, -10]} intensity={0.5} color="#39ff14" />
        <pointLight position={[0, 5, 0]} intensity={0.5} color="#F59E0B" />

        {/* Environment & Environment Effects */}
        <Environment preset="city" />
        <Stars radius={100} depth={50} count={2000} factor={4} saturation={0} fade speed={1} />
        <Sparkles count={100} scale={12} size={4} speed={0.4} opacity={0.2} color="#39ff14" />

        {/* 3D Objects Group */}
        <group position={[-3, 0, 0]}>
          <Island />
          <FertilizerBag position={[0, -0.5, 0]} />
        </group>

        {/* Tractor Island Bottom Right */}
        <TractorIsland position={[4.5, -3, 2]} />

        {/* Foreground Leaves */}
        <Leaves />

        {/* Controls and Shadows */}
        <OrbitControls enableZoom={false} enablePan={false} maxPolarAngle={Math.PI / 2 + 0.1} />
        <ContactShadows position={[0, -4.5, 0]} scale={20} blur={2} far={4.5} />
      </Canvas>
    </div>
  );
}
